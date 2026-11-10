<?php

namespace Webkul\Attendance\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Attendance\Services\EventPairingService;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Calendar;

/**
 * Re-applies the current shift rules to already paired automatic rows
 * (for example after an employee moved to a new shift) without touching
 * manual rows or locked payroll periods.
 */
class RebuildAttendanceAction
{
    public static function make(): Action
    {
        return Action::make('rebuildAttendance')
            ->label(__('attendance::filament/actions/rebuild-attendance.label'))
            ->modalHeading(__('attendance::filament/actions/rebuild-attendance.heading'))
            ->modalDescription(__('attendance::filament/actions/rebuild-attendance.description'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (): bool => static::canRebuild())
            ->authorize(fn (): bool => static::canRebuild())
            ->schema([
                DatePicker::make('date_from')
                    ->label(__('attendance::filament/actions/rebuild-attendance.date-from'))
                    ->native(false)
                    ->default(fn (): string => Carbon::today()->startOfMonth()->toDateString())
                    ->maxDate(fn (): string => Carbon::today()->toDateString())
                    ->required(),
                DatePicker::make('date_to')
                    ->label(__('attendance::filament/actions/rebuild-attendance.date-to'))
                    ->native(false)
                    ->default(fn (): string => Carbon::today()->toDateString())
                    ->afterOrEqual('date_from')
                    ->maxDate(fn (): string => Carbon::today()->toDateString())
                    ->required(),
                Select::make('calendar_id')
                    ->label(__('attendance::filament/actions/rebuild-attendance.calendar'))
                    ->options(fn (): array => Calendar::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->placeholder(__('attendance::filament/actions/rebuild-attendance.all-calendars')),
                Select::make('department_id')
                    ->label(__('attendance::filament/actions/rebuild-attendance.department'))
                    ->options(fn (): array => AttendanceOverviewService::departmentOptions())
                    ->searchable()
                    ->placeholder(__('attendance::filament/actions/rebuild-attendance.all-departments')),
                Select::make('employee_id')
                    ->label(__('attendance::filament/actions/rebuild-attendance.employee'))
                    ->options(fn (): array => AttendanceOverviewService::searchEligibleEmployees())
                    ->getSearchResultsUsing(fn (string $search): array => AttendanceOverviewService::searchEligibleEmployees($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => AttendanceOverviewService::employeeOptionLabel($value))
                    ->searchable()
                    ->placeholder(__('attendance::filament/actions/rebuild-attendance.all-employees')),
            ])
            ->action(function (array $data, $livewire): void {
                $from = Carbon::parse($data['date_from']);
                $to = Carbon::parse($data['date_to']);

                $employees = AttendanceOverviewService::eligibleEmployeeQuery()
                    ->when(filled($data['employee_id'] ?? null), fn (Builder $query) => $query->whereKey($data['employee_id']))
                    ->when(filled($data['department_id'] ?? null), fn (Builder $query) => AttendanceOverviewService::scopeByDepartment($query, (int) $data['department_id']))
                    ->when(filled($data['calendar_id'] ?? null), fn (Builder $query) => AttendanceOverviewService::scopeByCalendar($query, (int) $data['calendar_id']))
                    ->get();

                $pairing = app(EventPairingService::class);
                $rows = 0;

                $slugs = WriterRegistry::slugs();
                if (empty($slugs)) {
                    $slugs = ['biometric-attendance'];
                }

                foreach ($employees as $employee) {
                    foreach ($slugs as $slug) {
                        $rows += $pairing->rebuildForRange($employee, $slug, $from, $to)['rows'];
                    }
                }

                if (is_object($livewire) && method_exists($livewire, 'resetTable')) {
                    $livewire->resetTable();
                }

                Notification::make()
                    ->success()
                    ->title(__('attendance::filament/actions/rebuild-attendance.completed', [
                        'employees' => $employees->count(),
                        'rows'      => $rows,
                    ]))
                    ->send();
            });
    }

    protected static function canRebuild(): bool
    {
        return (bool) auth()->user()?->can('deleteAny', Attendance::class);
    }
}
