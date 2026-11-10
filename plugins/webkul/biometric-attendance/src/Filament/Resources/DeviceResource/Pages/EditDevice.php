<?php

namespace Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Tables\DevicesTable;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DevicesTable::makeTestConnectionAction()
                ->record(fn () => $this->getRecord()),
            DevicesTable::makeSyncNowAction()
                ->record(fn () => $this->getRecord()),
            DeleteAction::make(),
        ];
    }
}
