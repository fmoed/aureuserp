<?php

namespace Webkul\Attendance\Filament\Clusters\Configurations\Resources;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Actions\AssignEmployeesToShiftAction;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\CreateCalendar;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\EditCalendar;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\ListCalendars;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\ViewCalendar;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\RelationManagers\EmployeesRelationManager;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\RelationManagers\PublicHolidaysRelationManager;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Support\Filament\Resources\CalendarResource as BaseCalendarResource;
use Webkul\Support\Filament\Resources\CalendarResource\Tables\CalendarsTable;
use Webkul\Support\Models\Calendar;

class CalendarResource extends BaseCalendarResource
{
    protected static ?string $cluster = Configurations::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function getModelLabel(): string
    {
        return __('attendance::filament/clusters/configurations/resources/calendar.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('attendance::filament/clusters/configurations/resources/calendar.navigation.title');
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return __('attendance::filament/clusters/configurations.groups.schedules-and-devices');
    }

    public static function table(Table $table): Table
    {
        return CalendarsTable::configure($table)
            ->pushColumns([
                TextColumn::make('employees_count')
                    ->counts('employees')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar.table.columns.employees-count'))
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->tooltip(function (Calendar $record): ?string {
                        if (! AttendanceOverviewService::isDefaultCalendar($record)) {
                            return null;
                        }

                        return __('attendance::filament/clusters/configurations/resources/calendar.table.columns.employees-count-tooltip', [
                            'direct'    => (int) ($record->employees_count ?? 0),
                            'inherited' => AttendanceOverviewService::inheritedEmployeesCount($record),
                        ]);
                    }),
            ])
            ->pushRecordActions([
                AssignEmployeesToShiftAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ...parent::getRelations(),
            EmployeesRelationManager::class,
            PublicHolidaysRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCalendars::route('/'),
            'create' => CreateCalendar::route('/create'),
            'view'   => ViewCalendar::route('/{record}'),
            'edit'   => EditCalendar::route('/{record}/edit'),
        ];
    }
}
