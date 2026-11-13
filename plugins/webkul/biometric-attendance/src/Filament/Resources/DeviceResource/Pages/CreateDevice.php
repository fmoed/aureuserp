<?php

namespace Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Tables\DevicesTable;

class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DevicesTable::makeTestConnectionAction(),
        ];
    }
}
