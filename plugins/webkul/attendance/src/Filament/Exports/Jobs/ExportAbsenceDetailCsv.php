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
use SplTempFileObject;
use Webkul\Attendance\Services\AttendanceOverviewService;

/**
 * Writes one file per chunk of employees, fanning each employee out to
 * their workdays (date, day, standard status code, minutes) sorted by
 * employee then date — the daily register rows Filament's one-row-per-
 * record exporter cannot produce itself. Row counts are what progress
 * and the completion notification report on.
 */
class ExportAbsenceDetailCsv extends ExportCsv
{
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        /** @var Authenticatable $user */
        $user = $this->export->user;

        auth()->setUser($user);

        try {
            $query = EloquentSerializeFacade::unserialize($this->query);
            $employees = $query->find($this->records);

            $entries = app(AttendanceOverviewService::class)->daily(
                collect($employees),
                Carbon::parse($this->options['date_from']),
                Carbon::parse($this->options['date_to']),
            );

            $labels = [
                AttendanceOverviewService::STATUS_PRESENT          => __('attendance::filament/pages/overview.statuses.present'),
                AttendanceOverviewService::STATUS_ABSENT           => __('attendance::filament/pages/overview.statuses.absent'),
                AttendanceOverviewService::STATUS_ON_LEAVE         => __('attendance::filament/pages/overview.statuses.on-leave'),
                AttendanceOverviewService::STATUS_ON_LEAVE_PENDING => __('attendance::filament/pages/overview.statuses.pending'),
                AttendanceOverviewService::STATUS_HOLIDAY          => __('attendance::filament/pages/overview.statuses.holiday'),
            ];

            $csv = Writer::from(new SplTempFileObject);
            $csv->setDelimiter($this->exporter::getCsvDelimiter());

            $processedRows = 0;
            $successfulRows = 0;

            $rows = $entries
                ->map(fn (array $entry): array => [
                    'employee' => (string) $entry['name'],
                    'date'     => (string) $entry['date'],
                    'day'      => Carbon::parse($entry['date'])->translatedFormat('l'),
                    'status'   => $labels[$entry['status']] ?? $entry['status'],
                    'minutes'  => $entry['minutes'] ?? '',
                ])
                ->sortBy(fn (array $row): string => $row['employee'].'|'.$row['date'])
                ->values();

            foreach ($rows as $row) {
                $csv->insertOne(array_map(
                    fn (string $column): mixed => $row[$column] ?? '',
                    array_keys($this->columnMap),
                ));

                $successfulRows++;
                $processedRows++;
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

                $this->export->getFileDisk()->put($filePath, $csv->toString(), Filesystem::VISIBILITY_PRIVATE);
            });
        } finally {
            auth()->forgetGuards();
        }
    }
}
