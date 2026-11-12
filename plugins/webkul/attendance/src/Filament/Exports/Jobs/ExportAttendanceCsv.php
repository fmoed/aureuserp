<?php

namespace Webkul\Attendance\Filament\Exports\Jobs;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use Filament\Actions\Exports\Jobs\ExportCsv;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use League\Csv\Writer;
use League\Flysystem\UnableToWriteFile;
use SplTempFileObject;
use Throwable;
use Webkul\Attendance\Filament\Exports\AttendanceExporter;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Tables\AttendancesTable;
use Webkul\Attendance\Models\Attendance;
use Webkul\Employee\Models\Employee;

/**
 * Writes attendance records grouped by employee in the chunk order produced
 * by PrepareAttendanceExport, appending a per-employee summary row after each
 * employee's records and a blank separator row between employees when multiple
 * employees are exported.
 */
class ExportAttendanceCsv extends ExportCsv
{
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        /** @var Authenticatable $user */
        $user = $this->export->user;

        auth()->setUser($user);

        $locale = $this->options['locale'] ?? app()->getLocale();
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        AttendancesTable::flushRuntimeCache();

        try {
            $processedRows = 0;
            $successfulRows = 0;

            $csv = Writer::from(new SplTempFileObject);
            $csv->setDelimiter($this->exporter::getCsvDelimiter());

            $query = EloquentSerializeFacade::unserialize($this->query);
            $keyName = $query->getModel()->getKeyName();

            $recordsById = $query
                ->reorder()
                ->with(['employee.calendar.attendance'])
                ->whereKey($this->records)
                ->get()
                ->keyBy($keyName);

            $orderedRecords = collect($this->records)
                ->map(fn (mixed $id): ?Attendance => $recordsById->get($id))
                ->filter();

            $columnKeys = array_keys($this->columnMap);
            $multiEmployee = (bool) ($this->options['multi_employee'] ?? false);
            $lastEmployeeId = (int) ($this->options['last_employee_id'] ?? 0);

            /** @var AttendanceExporter $exporter */
            $exporter = $this->exporter;

            foreach ($orderedRecords->groupBy('employee_id') as $employeeId => $employeeRecords) {
                /** @var Employee|null $employee */
                $employee = $employeeRecords->first()?->employee;

                foreach ($employeeRecords as $record) {
                    try {
                        $csv->insertOne($exporter($record));

                        $successfulRows++;
                    } catch (Throwable $exception) {
                        report($exception);
                    }

                    $processedRows++;
                }

                if ($employee) {
                    $csv->insertOne($exporter->summaryRowForEmployee($employee, $employeeRecords, $columnKeys));
                }

                if ($multiEmployee && (int) $employeeId !== $lastEmployeeId) {
                    $csv->insertOne(array_fill(0, count($columnKeys), ''));
                }
            }

            $filePath = $this->export->getFileDirectory().DIRECTORY_SEPARATOR.str_pad(strval($this->page), 16, '0', STR_PAD_LEFT).'.csv';

            DB::transaction(function () use ($csv, $filePath, $processedRows, $successfulRows): void {
                $this->export::query()
                    ->whereKey($this->export->getKey())
                    ->lockForUpdate()
                    ->update([
                        'processed_rows'  => new Expression('processed_rows + '.$processedRows),
                        'successful_rows' => new Expression('successful_rows + '.$successfulRows),
                    ]);

                $this->export::query()
                    ->whereKey($this->export->getKey())
                    ->whereColumn('processed_rows', '>', 'total_rows')
                    ->lockForUpdate()
                    ->update([
                        'processed_rows' => new Expression('total_rows'),
                    ]);

                $this->export::query()
                    ->whereKey($this->export->getKey())
                    ->whereColumn('successful_rows', '>', 'total_rows')
                    ->lockForUpdate()
                    ->update([
                        'successful_rows' => new Expression('total_rows'),
                    ]);

                if (! $this->export->getFileDisk()->put($filePath, $csv->toString(), Filesystem::VISIBILITY_PRIVATE)) {
                    throw UnableToWriteFile::atLocation($filePath);
                }
            });
        } finally {
            auth()->forgetGuards();
        }
    }
}
