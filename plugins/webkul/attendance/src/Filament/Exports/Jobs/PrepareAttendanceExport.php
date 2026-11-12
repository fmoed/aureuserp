<?php

namespace Webkul\Attendance\Filament\Exports\Jobs;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use League\Csv\Bom;
use League\Csv\Writer;
use League\Flysystem\UnableToWriteFile;
use SplTempFileObject;

/**
 * Prepares the grouped attendance records CSV export: writes headers, clears
 * the table's default sort, orders records chronologically within each
 * employee group (sorted alphabetically by employee name), and distributes
 * employee groups into chunks without ever splitting an employee across batches.
 */
class PrepareAttendanceExport extends PrepareCsvExport
{
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $locale = $this->options['locale'] ?? app()->getLocale();
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        $csv = Writer::from(new SplTempFileObject);
        $csv->setOutputBOM(Bom::Utf8);
        $csv->setDelimiter($this->exporter::getCsvDelimiter());
        $csv->insertOne(array_values($this->columnMap));

        $filePath = $this->export->getFileDirectory().DIRECTORY_SEPARATOR.'headers.csv';

        if (! $this->export->getFileDisk()->put($filePath, $csv->toString(), Filesystem::VISIBILITY_PRIVATE)) {
            throw UnableToWriteFile::atLocation($filePath);
        }

        $query = EloquentSerializeFacade::unserialize($this->query);

        if ($this->records !== null) {
            $query->whereKey($this->records);
        }

        $table = $query->getModel()->getTable();

        $records = $query
            ->reorder()
            ->with('employee:id,name')
            ->orderBy($table.'.work_date')
            ->orderBy($table.'.check_in')
            ->get([$table.'.id', $table.'.employee_id', $table.'.work_date', $table.'.check_in']);

        $employeeGroups = $records
            ->groupBy('employee_id')
            ->sortBy(fn (Collection $group): string => mb_strtolower((string) ($group->first()?->employee?->name ?? '')));

        // We do not want to send the loaded user relationship to the queue in job payloads.
        $this->export->unsetRelation('user');

        if ($employeeGroups->isEmpty()) {
            return;
        }

        $chunks = [];
        $currentChunk = [];

        foreach ($employeeGroups as $group) {
            $ids = $group->pluck('id')->all();

            if ($currentChunk !== [] && (count($currentChunk) + count($ids)) > $this->chunkSize) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
            }

            array_push($currentChunk, ...$ids);
        }

        if ($currentChunk !== []) {
            $chunks[] = $currentChunk;
        }

        $options = array_merge($this->options, [
            'multi_employee'   => $employeeGroups->count() > 1,
            'last_employee_id' => $employeeGroups->keys()->last(),
        ]);

        $jobs = [];
        $page = 0;

        foreach ($chunks as $recordIds) {
            $page++;

            $jobs[] = app($this->getExportCsvJob(), [
                'export'    => $this->export,
                'query'     => $this->query,
                'records'   => $recordIds,
                'page'      => $page,
                'columnMap' => $this->columnMap,
                'options'   => $options,
            ]);
        }

        $this->batch()->add($jobs);
    }

    public function getExportCsvJob(): string
    {
        return ExportAttendanceCsv::class;
    }
}
