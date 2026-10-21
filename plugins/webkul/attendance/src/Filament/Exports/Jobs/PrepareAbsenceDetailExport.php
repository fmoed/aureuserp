<?php

namespace Webkul\Attendance\Filament\Exports\Jobs;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use League\Csv\Bom;
use League\Csv\Writer;
use SplTempFileObject;
use Webkul\Attendance\Filament\Exports\AbsenceDetailExporter;
use Webkul\Attendance\Services\AttendanceOverviewService;

/**
 * Fan-out prepare for the daily register export. Filament's exporter
 * contract yields one row per record, but this export needs one row per
 * employee and workday — so the prepare job recounts the export as rows
 * (progress and clamping depend on it), then dispatches one CSV job per
 * chunk of employees, each writing its own fan-out file.
 */
class PrepareAbsenceDetailExport extends PrepareCsvExport
{
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $csv = Writer::from(new SplTempFileObject);
        $csv->setOutputBOM(Bom::Utf8);
        $csv->setDelimiter($this->exporter::getCsvDelimiter());
        $csv->insertOne(array_values($this->columnMap));

        $filePath = $this->export->getFileDirectory().DIRECTORY_SEPARATOR.'headers.csv';
        $this->export->getFileDisk()->put($filePath, $csv->toString(), Filesystem::VISIBILITY_PRIVATE);

        $query = EloquentSerializeFacade::unserialize($this->query);
        $employees = $query->get();

        $rowCount = app(AttendanceOverviewService::class)->countDaily(
            $employees,
            Carbon::parse($this->options['date_from']),
            Carbon::parse($this->options['date_to']),
        );

        // We do not want to send the loaded user relationship to the queue in job payloads.
        $this->export->unsetRelation('user');

        // The action counted employees; the file holds employee × workday rows.
        $this->export->forceFill(['total_rows' => $rowCount])->save();

        if ($rowCount === 0 || $rowCount > AbsenceDetailExporter::maxRows()) {
            return;
        }

        $jobs = [];
        $page = 0;

        foreach (array_chunk($employees->pluck('id')->all(), $this->chunkSize) as $records) {
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
