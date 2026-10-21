<?php

namespace Webkul\Attendance\Filament\Clusters\Configurations\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\CreateCalendar;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\EditCalendar;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\ListCalendars;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\ViewCalendar;
use Webkul\Support\Filament\Resources\CalendarResource\RelationManagers\CalendarAttendance;
use Webkul\Support\Filament\Resources\CalendarResource\Schemas\CalendarForm;
use Webkul\Support\Filament\Resources\CalendarResource\Schemas\CalendarInfolist;
use Webkul\Support\Filament\Resources\CalendarResource\Tables\CalendarsTable;
use Webkul\Support\Models\Calendar;

class CalendarResource extends Resource
{
    protected static ?string $model = Calendar::class;

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function getModelLabel(): string
    {
        return __('attendance::filament/clusters/configurations/resources/calendar.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('attendance::filament/clusters/configurations/resources/calendar.navigation.title');
    }

    public static function form(Schema $schema): Schema
    {
        return CalendarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CalendarsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CalendarInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            CalendarAttendance::class,
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
