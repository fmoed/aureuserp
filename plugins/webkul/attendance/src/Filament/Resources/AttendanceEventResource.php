<?php

namespace Webkul\Attendance\Filament\Resources;

use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Webkul\Attendance\Filament\Resources\AttendanceEventResource\Pages\ListAttendanceEvents;
use Webkul\Attendance\Filament\Resources\AttendanceEventResource\Tables\AttendanceEventsTable;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Support\Enums\NavigationGroup;

class AttendanceEventResource extends Resource
{
    protected static ?string $model = AttendanceEvent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    public static function getModelLabel(): string
    {
        return __('attendance::filament/resources/attendance-event.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('attendance::filament/resources/attendance-event.navigation.title');
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Attendance;
    }

    public static function table(Table $table): Table
    {
        return AttendanceEventsTable::configure($table);
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'attendance/attendance-events';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendanceEvents::route('/'),
        ];
    }
}
