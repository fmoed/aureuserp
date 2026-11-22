<?php

namespace Webkul\Attendance\Filament\Exports\Jobs;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use League\Csv\Bom;
use League\Csv\Writer;
use SplTempFileObject;
use Webkul\Attendance\Filament\Exports\AbsenceDetailExporter;
use Webkul\Attendance\Services\AttendanceOverviewService;

/**
 * Prepares the daily attendance matrix export: writes the CSV headers
 * (fixed overview columns followed by a column for each calendar day in
 * the selected range), counts eligible employees (one row per employee),
 * and dispatches chunked CSV jobs.
 */
class PrepareAbsenceDetailExport extends PrepareCsvExport
{
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $locale = $this->options['locale'] ?? app()->getLocale();
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        $from = Carbon::parse($this->options['date_from']);
        $to = Carbon::parse($this->options['date_to']);

        $headers = $this->columnMap !== []
            ? array_values($this->columnMap)
            : collect(AbsenceDetailExporter::getColumns())
                ->map(fn (ExportColumn $column): string => (string) $column->getLabel())
                ->values()
                ->all();

        $hiddenDates = array_flip((array) ($this->options['hidden_dates'] ?? []));

        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $dateStr = $cursor->toDateString();

            if (! isset($hiddenDates[$dateStr])) {
                $headers[] = $cursor->locale($locale)->translatedFormat('Y-m-d (D)');
            }

            $cursor->addDay();
        }

        $csv = Writer::from(new SplTempFileObject);
        $csv->setOutputBOM(Bom::Utf8);
        $csv->setDelimiter($this->exporter::getCsvDelimiter());
        $csv->insertOne($headers);

        $filePath = $this->export->getFileDirectory().DIRECTORY_SEPARATOR.'headers.csv';
        $this->export->getFileDisk()->put($filePath, $csv->toString(), Filesystem::VISIBILITY_PRIVATE);

        $query = EloquentSerializeFacade::unserialize($this->query);
        $employees = $query->get();

        $employeeIds = app(AttendanceOverviewService::class)->employeeIdsWithWorkdays($employees, $from, $to);
        $rowCount = count($employeeIds);

        // We do not want to send the loaded user relationship to the queue in job payloads.
        $this->export->unsetRelation('user');

        $this->export->forceFill(['total_rows' => $rowCount])->save();

        if ($rowCount === 0 || $rowCount > AbsenceDetailExporter::maxRows()) {
            return;
        }

        $jobs = [];
        $page = 0;

        foreach (array_chunk($employeeIds, $this->chunkSize) as $records) {
            $page++;

            $jobs[] = app($this->getExportCsvJob(), [
                'export'    => $this->export,
                'query'     => $this->query,
                'records'   => $records,
                'page'      => $page,
                'columnMap' => $this->columnMap,
                'options'   => $this->options,
            ]);
        }

        $this->batch()->add($jobs);
    }

    public function getExportCsvJob(): string
    {
        return ExportAbsenceDetailCsv::class;
    }
}
