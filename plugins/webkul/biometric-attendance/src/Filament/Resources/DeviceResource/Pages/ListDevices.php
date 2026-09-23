<?php

namespace Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('biometric-attendance::filament/resources/device/pages/list-devices.header-actions.create.label'))
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
