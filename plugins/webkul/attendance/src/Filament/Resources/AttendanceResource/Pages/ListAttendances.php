<?php

namespace Webkul\Attendance\Filament\Resources\AttendanceResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;
use Webkul\Attendance\Filament\Actions\RebuildAttendanceAction;
use Webkul\Attendance\Filament\Resources\AttendanceResource;
use Webkul\Attendance\Filament\Widgets\AttendanceStatsWidget;
use Webkul\PluginManager\Package;
use Webkul\RemoteAttendance\Filament\Widgets\RemoteCheckInWidget;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    public function getHeaderWidgets(): array
    {
        $widgets = [
            AttendanceStatsWidget::make(),
        ];

        if (
            Package::isPluginInstalled('remote-attendance')
            && class_exists(RemoteCheckInWidget::class)
            && RemoteCheckInWidget::canView()
        ) {
            array_unshift($widgets, RemoteCheckInWidget::make());
        }

        return $widgets;
    }

    #[On('remote-attendance-updated')]
    public function refreshRemoteAttendance(): void
    {
        // Livewire re-renders the table automatically after this listener fires.
    }

    protected function getHeaderActions(): array
    {
        return [
            RebuildAttendanceAction::make(),
            CreateAction::make(),
        ];
    }
}
