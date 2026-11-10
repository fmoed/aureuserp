<?php

namespace Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Webkul\Attendance\Policies\AttendancePolicy;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Support\Models\Calendar;

/**
 * Assign one shift to a whole group at once: pick a department and/or a job
 * position (applies to every matching employee) and/or hand-pick employees.
 * Employees are always limited to the shift's company. The action works both
 * as a header action (calendar passed explicitly) and as a row action (the
 * record resolves to the calendar).
 */
class AssignEmployeesToShiftAction
{
    public static function make(?Calendar $calendar = null): Action
    {
        $resolveCalendar = fn (?Model $record): ?Calendar => $calendar ?? ($record instanceof Calendar ? $record : null);

        return Action::make('assignEmployees')
            ->label(__('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.label'))
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->visible(fn (): bool => static::canAssign())
            ->authorize(fn (): bool => static::canAssign())
            ->schema([
                Select::make('department_id')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.department'))
                    ->options(fn (?Model $record): array => AttendanceOverviewService::departmentOptions($resolveCalendar($record)?->company_id))
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('job_id', null))
                    ->helperText(__('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.department-hint')),
                Select::make('job_id')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.job'))
                    ->options(fn (?Model $record, Get $get): array => EmployeeJobPosition::query()
                        ->when($resolveCalendar($record)?->company_id, fn (Builder $query, int $companyId) => $query->where('company_id', $companyId))
                        ->when(filled($get('department_id')), fn (Builder $query) => AttendanceOverviewService::scopeByDepartment($query, (int) $get('department_id')))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->live()
                    ->helperText(__('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.job-hint')),
                Select::make('employee_ids')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.employees'))
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(fn (?Model $record, string $search, Get $get): array => static::employeesQuery($resolveCalendar($record))
                        ->when(filled($get('department_id')), fn (Builder $query) => AttendanceOverviewService::scopeByDepartment($query, (int) $get('department_id')))
                        ->when(filled($get('job_id')), fn (Builder $query) => $query->where('job_id', $get('job_id')))
                        ->where('name', 'like', '%'.$search.'%')
                        ->orderBy('name')
                        ->limit(50)
                        ->pluck('name', 'id')
                        ->all())
                    ->getOptionLabelsUsing(fn (?Model $record, array $values): array => static::employeesQuery($resolveCalendar($record))
                        ->whereKey($values)
                        ->pluck('name', 'id')
                        ->all())
                    ->helperText(__('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.employees-hint')),
                TextEntry::make('preview_count')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.preview-label'))
                    ->state(fn (?Model $record, Get $get): int => static::matchingCount($resolveCalendar($record), $get))
                    ->formatStateUsing(fn (int $state): string => __('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.preview-count', [
                        'count' => $state,
                    ]))
                    ->visible(fn (Get $get): bool => filled($get('employee_ids')) || filled($get('department_id')) || filled($get('job_id'))),
            ])
            ->action(function (?Model $record, array $data) use ($resolveCalendar): void {
                $resolved = $resolveCalendar($record);

                if ($resolved === null) {
                    return;
                }

                $employees = static::employeesQuery($resolved);

                $employeeIds = array_values(array_filter((array) ($data['employee_ids'] ?? [])));

                if ($employeeIds !== []) {
                    $employees->whereKey($employeeIds);
                } elseif (filled($data['department_id'] ?? null) || filled($data['job_id'] ?? null)) {
                    $employees
                        ->when(filled($data['department_id'] ?? null), fn (Builder $query) => AttendanceOverviewService::scopeByDepartment($query, (int) $data['department_id']))
                        ->when(filled($data['job_id'] ?? null), fn (Builder $query) => $query->where('job_id', $data['job_id']));
                } else {
                    Notification::make()
                        ->warning()
                        ->title(__('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.select-required'))
                        ->send();

                    return;
                }

                $updated = $employees->update(['calendar_id' => $resolved->getKey()]);

                Notification::make()
                    ->success()
                    ->title(__('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.assigned', [
                        'count' => $updated,
                    ]))
                    ->send();
            });
    }

    protected static function canAssign(): bool
    {
        return (bool) (auth()->user()?->can('update_employee_employee')
            || auth()->user()?->can('update_employees_employee'));
    }

    protected static function employeesQuery(?Calendar $calendar): Builder
    {
        return AttendancePolicy::scopeEmployeeQuery(
            Employee::query()
                ->when($calendar?->company_id, fn (Builder $query, int $companyId) => $query->where('company_id', $companyId))
        );
    }

    protected static function matchingCount(?Calendar $calendar, Get $get): int
    {
        if ($calendar === null) {
            return 0;
        }

        $employeeIds = array_values(array_filter((array) ($get('employee_ids') ?? [])));

        if ($employeeIds !== []) {
            return static::employeesQuery($calendar)->whereKey($employeeIds)->count();
        }

        if (blank($get('department_id')) && blank($get('job_id'))) {
            return 0;
        }

        return static::employeesQuery($calendar)
            ->when(filled($get('department_id')), fn (Builder $query) => AttendanceOverviewService::scopeByDepartment($query, (int) $get('department_id')))
            ->when(filled($get('job_id')), fn (Builder $query) => $query->where('job_id', $get('job_id')))
            ->count();
    }
}
