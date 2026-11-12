<?php

namespace Webkul\Attendance\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Webkul\Attendance\Filament\Exports\Concerns\HasAbsenceExportOptions;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Tables\AttendancesTable;
use Webkul\Attendance\Filament\Tables\AttendanceRegisterTable;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Policies\AttendancePolicy;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Employee;

class AttendanceExporter extends Exporter
{
    use HasAbsenceExportOptions;

    protected static ?string $model = Attendance::class;

    protected static function exportFilePrefix(): string
    {
        return 'attendances';
    }

    public static function scopeQuery(Builder $query, array $options): Builder
    {
        return AttendancePolicy::scopeQuery($query)
            ->when(
                $options['employee_id'] ?? null,
                fn (Builder $query, mixed $employeeId) => $query->whereIn('employee_id', (array) $employeeId)
            )
            ->when(
                $options['date_from'] ?? null,
                fn (Builder $query, mixed $dateFrom) => $query->whereDate('work_date', '>=', $dateFrom)
            )
            ->when(
                $options['date_to'] ?? null,
                fn (Builder $query, mixed $dateTo) => $query->whereDate('work_date', '<=', $dateTo)
            )
            ->when(
                $options['department_id'] ?? null,
                fn (Builder $query, mixed $departmentId) => $query->whereHas('employee', fn (Builder $employee) => AttendanceOverviewService::scopeByDepartment($employee, (array) $departmentId))
            )
            ->when(
                $options['calendar_id'] ?? null,
                fn (Builder $query, mixed $calendarId) => $query->whereHas('employee', fn (Builder $employee) => AttendanceOverviewService::scopeByCalendar($employee, (array) $calendarId))
            );
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('employee.name')
                ->label(__('attendance::filament/exports/attendance.columns.employee')),
            ExportColumn::make('work_date')
                ->label(__('attendance::filament/exports/attendance.columns.work-date'))
                ->formatStateUsing(fn ($state): string => $state ? Carbon::parse($state)->format('Y-m-d') : ''),
            ExportColumn::make('check_in')
                ->label(__('attendance::filament/exports/attendance.columns.check-in'))
                ->formatStateUsing(fn ($state, Attendance $record): string => AttendancesTable::employeeWallClock($record, 'check_in')?->format('Y-m-d H:i:s') ?? ''),
            ExportColumn::make('check_out')
                ->label(__('attendance::filament/exports/attendance.columns.check-out'))
                ->formatStateUsing(fn ($state, Attendance $record): string => AttendancesTable::employeeWallClock($record, 'check_out')?->format('Y-m-d H:i:s') ?? ''),
            ExportColumn::make('scheduled_minutes')
                ->label(__('attendance::filament/exports/attendance.columns.scheduled'))
                ->state(fn (Attendance $record): int => AttendancesTable::scheduledMinutes($record))
                ->formatStateUsing(fn ($state): string => AttendanceRegisterTable::formatMinutesToHours((int) $state)),
            ExportColumn::make('worked_minutes')
                ->label(__('attendance::filament/exports/attendance.columns.worked'))
                ->formatStateUsing(fn ($state): string => $state === null ? '' : AttendanceRegisterTable::formatMinutesToHours((int) $state)),
            ExportColumn::make('remaining_minutes')
                ->label(__('attendance::filament/exports/attendance.columns.remaining'))
                ->state(fn (Attendance $record): ?int => AttendancesTable::remainingMinutes($record))
                ->formatStateUsing(fn ($state): string => $state === null ? '' : AttendanceRegisterTable::formatMinutesToHours((int) $state)),
            ExportColumn::make('break_minutes')
                ->label(__('attendance::filament/exports/attendance.columns.break'))
                ->formatStateUsing(fn ($state): string => AttendanceRegisterTable::formatMinutesToHours((int) ($state ?? 0))),
            ExportColumn::make('is_estimated')
                ->label(__('attendance::filament/exports/attendance.columns.estimated'))
                ->formatStateUsing(fn ($state): string => $state ? __('attendance::filament/exports/attendance.columns.estimated') : ''),
            ExportColumn::make('source')
                ->label(__('attendance::filament/exports/attendance.columns.source'))
                ->formatStateUsing(fn (?string $state, Attendance $record): string => Attendance::sourceDisplayName($state ?? $record->source, $record->source_label)),
        ];
    }

    /**
     * Builds the per-employee summary row appended after an employee's
     * exported sessions, deduplicating scheduled/shortfall hours per work_date.
     *
     * @param  Collection<int, Attendance>  $records
     * @param  list<string>  $columnKeys
     * @return list<string>
     */
    public function summaryRowForEmployee(Employee $employee, Collection $records, array $columnKeys): array
    {
        $byDate = $records->groupBy(function (Attendance $record): string {
            return $record->work_date instanceof Carbon
                ? $record->work_date->toDateString()
                : (string) $record->getRawOriginal('work_date');
        });

        $daysCount = $byDate->count();
        $incompleteDaysCount = 0;
        $totalScheduledMinutes = 0;
        $totalRemainingMinutes = 0;

        foreach ($byDate as $dayRecords) {
            /** @var Attendance $first */
            $first = $dayRecords->first();
            $dayScheduled = AttendancesTable::scheduledMinutes($first);

            if ($dayRecords->contains(fn (Attendance $record): bool => $record->check_out !== null)) {
                $totalScheduledMinutes += $dayScheduled;
                $dayWorked = (int) $dayRecords->sum(fn (Attendance $record): int => (int) ($record->worked_minutes ?? 0));
                $totalRemainingMinutes += max(0, $dayScheduled - $dayWorked);
            } else {
                $incompleteDaysCount++;
            }
        }

        $totalWorkedMinutes = (int) $records->sum(fn (Attendance $record): int => (int) ($record->worked_minutes ?? 0));
        $totalBreakMinutes = (int) $records->sum(fn (Attendance $record): int => (int) ($record->break_minutes ?? 0));

        $values = [
            'employee.name'     => __('attendance::filament/exports/attendance.summary.total-for', ['employee' => $employee->name]),
            'work_date'         => __('attendance::filament/exports/attendance.summary.days-count', ['count' => $daysCount]),
            'check_out'         => $incompleteDaysCount > 0
                ? __('attendance::filament/exports/attendance.summary.missing-checkouts', ['count' => $incompleteDaysCount])
                : '',
            'scheduled_minutes' => AttendanceRegisterTable::formatMinutesToHours($totalScheduledMinutes),
            'worked_minutes'    => AttendanceRegisterTable::formatMinutesToHours($totalWorkedMinutes),
            'remaining_minutes' => AttendanceRegisterTable::formatMinutesToHours($totalRemainingMinutes),
            'break_minutes'     => AttendanceRegisterTable::formatMinutesToHours($totalBreakMinutes),
        ];

        return array_map(
            fn (string $key): string => $values[$key] ?? '',
            $columnKeys,
        );
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = __('attendance::filament/exports/attendance.notification.completed', [
            'count' => number_format($export->successful_rows),
        ]);

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.__('attendance::filament/exports/attendance.notification.failed', [
                'count' => number_format($failedRowsCount),
            ]);
        }

        return $body;
    }
}
