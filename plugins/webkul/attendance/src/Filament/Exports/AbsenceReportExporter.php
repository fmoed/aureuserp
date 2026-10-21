<?php

namespace Webkul\Attendance\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Filament\Exports\Concerns\HasAbsenceExportOptions;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Services\CompanyContext;

class AbsenceReportExporter extends Exporter
{
    use HasAbsenceExportOptions;

    protected static ?string $model = Employee::class;

    protected static function exportFilePrefix(): string
    {
        return 'absence-summary';
    }

    /**
     * Shared engine for the whole export job: the column closures below run
     * per row, so resolving once here (instead of per row) keeps calendar
     * fallback lookups memoized across the job.
     */
    protected ?AttendanceOverviewService $overviewService = null;

    /**
     * Per-record summary memo: four export columns ask for the same summary,
     * and without this the daily engine ran once per column per employee.
     *
     * @var array<string, array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}>
     */
    protected array $summaryMemo = [];

    protected function overviewService(): AttendanceOverviewService
    {
        return $this->overviewService ??= app(AttendanceOverviewService::class);
    }

    /**
     * A full year: one aggregated row per employee stays cheap (one batched
     * summary per row, queued and chunked), so the summary export is not
     * bound by the 93-day interactive register guard.
     */
    protected static function exportRangeLimit(): int
    {
        return AttendanceOverviewService::MAX_SUMMARY_RANGE_DAYS;
    }

    /**
     * Query scope for the export run, applied through the action's
     * `modifyQueryUsing()` (which is the only hook that receives the form
     * options). Mirrors the page: company predicate including company-less
     * employees (CompanyScope semantics, repeated explicitly because the
     * serialized query runs in a queue worker where global scopes are off),
     * plus the employee option.
     *
     * @param  array<string, mixed>  $options
     */
    public static function scopeQuery(Builder $query, array $options): Builder
    {
        $ids = app(CompanyContext::class)->activeIds();

        return AttendanceOverviewService::scopeEligibleEmployees($query)
            ->when(
                $ids !== [],
                fn (Builder $query) => $query->where(
                    fn (Builder $query) => $query
                        ->whereIn('company_id', $ids)
                        ->orWhereNull('company_id')
                )
            )
            ->when(
                $options['employee_id'] ?? null,
                fn (Builder $query, mixed $employeeId) => $query->whereKey($employeeId)
            )
            ->orderBy('name');
    }

    /**
     * One row per employee, matching the manual absence-report shape
     * (employee, workdays, present, absent, rate) — the trailing
     * informational columns stay opt-in so the default file keeps the
     * manual shape (the scope form lives in HasAbsenceExportOptions).
     *
     * @return array<int, ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label(__('attendance::filament/exports/absence-report.columns.employee')),
            ExportColumn::make('workdays')
                ->label(__('attendance::filament/exports/absence-report.columns.workdays'))
                ->formatStateUsing(fn (Employee $record, array $options, self $exporter): int => $exporter->summaryFor($record, $options)['workdays']),
            ExportColumn::make('present')
                ->label(__('attendance::filament/exports/absence-report.columns.present'))
                ->formatStateUsing(fn (Employee $record, array $options, self $exporter): int => $exporter->summaryFor($record, $options)['present']),
            ExportColumn::make('absent')
                ->label(__('attendance::filament/exports/absence-report.columns.absent'))
                ->formatStateUsing(fn (Employee $record, array $options, self $exporter): int => $exporter->summaryFor($record, $options)['absent']),
            ExportColumn::make('absence_rate')
                ->label(__('attendance::filament/exports/absence-report.columns.absence-rate'))
                ->formatStateUsing(fn (Employee $record, array $options, self $exporter): string => self::formatRate($exporter->summaryFor($record, $options)['absence_rate'])),
            ExportColumn::make('on_leave')
                ->label(__('attendance::filament/exports/absence-report.columns.on-leave'))
                ->enabledByDefault(false)
                ->formatStateUsing(fn (Employee $record, array $options, self $exporter): int => $exporter->summaryFor($record, $options)['on_leave']),
            ExportColumn::make('holidays')
                ->label(__('attendance::filament/exports/absence-report.columns.holidays'))
                ->enabledByDefault(false)
                ->formatStateUsing(fn (Employee $record, array $options, self $exporter): int => $exporter->summaryFor($record, $options)['holidays']),
            ExportColumn::make('pending')
                ->label(__('attendance::filament/exports/absence-report.columns.pending'))
                ->enabledByDefault(false)
                ->formatStateUsing(fn (Employee $record, array $options, self $exporter): int => $exporter->summaryFor($record, $options)['pending']),
            ExportColumn::make('minutes')
                ->label(__('attendance::filament/exports/absence-report.columns.minutes'))
                ->enabledByDefault(false)
                ->formatStateUsing(fn (Employee $record, array $options, self $exporter): int => $exporter->summaryFor($record, $options)['minutes']),
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string}  $options
     * @return array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}
     */
    protected function summaryFor(Employee $record, array $options): array
    {
        $key = $record->getKey().'|'.$options['date_from'].'|'.$options['date_to'];

        return $this->summaryMemo[$key] ??= $this->overviewService()->summaryFor(
            $record,
            Carbon::parse($options['date_from']),
            Carbon::parse($options['date_to']),
        );
    }

    protected static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 1, '.', ''), '0'), '.').'%';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = __('attendance::filament/exports/absence-report.notification.completed', [
            'count' => number_format($export->successful_rows),
        ]);

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.__('attendance::filament/exports/absence-report.notification.failed', [
                'count' => number_format($failedRowsCount),
            ]);
        }

        return $body;
    }
}
