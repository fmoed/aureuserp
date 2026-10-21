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
use Webkul\Attendance\Filament\Tables\AttendanceRegisterTable;
use Webkul\Attendance\Services\AttendanceOverviewService;

/**
 * Writes one matrix row per employee in the chunk: employee name,
 * schedule, workdays, present, absent, on-leave, hours, absence rate, and
 * the status label for each calendar day in the requested date range.
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

        $locale = $this->options['locale'] ?? app()->getLocale();
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        try {
            $query = EloquentSerializeFacade::unserialize($this->query);
            $employees = $query->find($this->records);

            $from = Carbon::parse($this->options['date_from']);
            $to = Carbon::parse($this->options['date_to']);

            $matrix = app(AttendanceOverviewService::class)->register(collect($employees), $from, $to);

            $dates = [];
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $dates[] = $cursor->toDateString();
                $cursor->addDay();
            }

            $csv = Writer::from(new SplTempFileObject);
            $csv->setDelimiter($this->exporter::getCsvDelimiter());

            $processedRows = 0;
            $successfulRows = 0;

            foreach ($matrix as $row) {
                $hoursStr = sprintf('%02d:%02d', intdiv($row['totals']['minutes'], 60), $row['totals']['minutes'] % 60);

                $line = [
                    (string) $row['name'],
                    (string) ($row['calendar'] ?? '—'),
                    (string) $row['totals']['workdays'],
                    (string) $row['totals']['present'],
                    (string) $row['totals']['absent'],
                    (string) $row['totals']['on_leave'],
                    $hoursStr,
                    $row['totals']['absence_rate'].'%',
                ];

                foreach ($dates as $date) {
                    $status = (string) ($row['cells'][$date] ?? '');
                    $label = AttendanceRegisterTable::statusLabel($status);
                    $line[] = $label !== '' ? $label : '—';
                }

                $csv->insertOne($line);

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
