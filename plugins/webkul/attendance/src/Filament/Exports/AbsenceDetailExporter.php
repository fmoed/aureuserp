<?php

namespace Webkul\Attendance\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Webkul\Attendance\Filament\Exports\Concerns\HasAbsenceExportOptions;
use Webkul\Employee\Models\Employee;

/**
 * Daily register export: one row per employee and workday (date, day of
 * week, standard status code, minutes). Fan-out is handled by
 * PrepareAbsenceDetailExport / ExportAbsenceDetailCsv because Filament's
 * exporter contract returns exactly one row per record.
 */
class AbsenceDetailExporter extends Exporter
{
    use HasAbsenceExportOptions;

    protected static ?string $model = Employee::class;

    protected static function exportFilePrefix(): string
    {
        return 'absence-detail';
    }

    /**
     * Row cap counts export rows (employee × workday), not employees.
     * Config-overridable so operations can tighten it without a release
     * and tests can exercise the abort path cheaply.
     */
    public static function maxRows(): int
    {
        return (int) config('attendance.export.max_detail_rows', 10000);
    }

    /**
     * @return array<int, ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('employee')
                ->label(__('attendance::filament/exports/absence-detail.columns.employee')),
            ExportColumn::make('date')
                ->label(__('attendance::filament/exports/absence-detail.columns.date')),
            ExportColumn::make('day')
                ->label(__('attendance::filament/exports/absence-detail.columns.day')),
            ExportColumn::make('status')
                ->label(__('attendance::filament/exports/absence-detail.columns.status')),
            ExportColumn::make('minutes')
                ->label(__('attendance::filament/exports/absence-detail.columns.minutes')),
        ];
    }

    public static function getCompletedNotificationTitle(Export $export): string
    {
        if (static::hitRowCap($export)) {
            return __('attendance::filament/exports/absence-detail.notification.cap-title');
        }

        return parent::getCompletedNotificationTitle($export);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        if (static::hitRowCap($export)) {
            return __('attendance::filament/exports/absence-detail.notification.cap-reached', [
                'count' => number_format($export->total_rows),
                'limit' => number_format(static::maxRows()),
            ]);
        }

        $body = __('attendance::filament/exports/absence-detail.notification.completed', [
            'count' => number_format($export->successful_rows),
        ]);

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.__('attendance::filament/exports/absence-detail.notification.failed', [
                'count' => number_format($failedRowsCount),
            ]);
        }

        return $body;
    }

    /**
     * The prepare job aborts (writing no data rows) when the requested
     * range exceeds the row cap: every row failed, and the recount shows
     * the request was over the limit rather than failing mid-flight.
     */
    protected static function hitRowCap(Export $export): bool
    {
        return $export->successful_rows === 0
            && $export->total_rows > static::maxRows();
    }
}
