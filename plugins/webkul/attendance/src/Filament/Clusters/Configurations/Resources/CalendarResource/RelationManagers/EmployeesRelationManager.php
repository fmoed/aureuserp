<?php

namespace Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Actions\AssignEmployeesToShiftAction;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Employee;

class EmployeesRelationManager extends RelationManager
{
    protected static string $relationship = 'employees';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = static::directEmployeesCount($ownerRecord);

        return $count > 0 ? (string) $count : null;
    }

    /**
     * Read preloaded `employees_count` when present, otherwise query the
     * relation directly without mutating the owner Eloquent model (which
     * would mark `employees_count` dirty when EditRecord saves the parent).
     */
    protected static function directEmployeesCount(Model $ownerRecord): int
    {
        if ($ownerRecord->getAttribute('employees_count') !== null) {
            return (int) $ownerRecord->getAttribute('employees_count');
        }

        return (int) $ownerRecord->employees()->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(function (): string {
                $calendar = $this->getOwnerRecord();
                $direct = static::directEmployeesCount($calendar);

                if (! AttendanceOverviewService::isDefaultCalendar($calendar)) {
                    return __('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.direct-shift-summary', [
                        'direct' => $direct,
                    ]);
                }

                $inherited = AttendanceOverviewService::inheritedEmployeesCount($calendar);

                return __('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.default-shift-summary', [
                    'direct'    => $direct,
                    'inherited' => $inherited,
                    'total'     => $direct + $inherited,
                ]);
            })
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['department', 'job']))
            ->recordUrl(fn (Employee $record): ?string => auth()->user()?->can('view', $record)
                ? EmployeeResource::getUrl('view', ['record' => $record])
                : null)
            ->columns([
                TextColumn::make('name')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->url(fn (Employee $record): ?string => auth()->user()?->can('view', $record)
                        ? EmployeeResource::getUrl('view', ['record' => $record])
                        : null),
                TextColumn::make('barcode')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.columns.barcode'))
                    ->placeholder('—'),
                TextColumn::make('department.complete_name')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.columns.department'))
                    ->placeholder('—'),
                TextColumn::make('job.name')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.columns.job'))
                    ->placeholder('—'),
                TextColumn::make('time_zone')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.columns.time-zone'))
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('unassignFromShift')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.actions.unassign.label'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.actions.unassign.description'))
                    ->visible(fn (): bool => static::canAssign())
                    ->authorize(fn (): bool => static::canAssign())
                    ->action(function (Employee $record): void {
                        $record->update(['calendar_id' => null]);

                        Notification::make()
                            ->success()
                            ->title(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.actions.unassign.success', [
                                'employee' => $record->name,
                            ]))
                            ->send();
                    }),
            ])
            ->headerActions([
                AssignEmployeesToShiftAction::make($this->getOwnerRecord()),
            ]);
    }

    protected static function canAssign(): bool
    {
        return (bool) (auth()->user()?->can('update_employee_employee')
            || auth()->user()?->can('update_employees_employee'));
    }
}
