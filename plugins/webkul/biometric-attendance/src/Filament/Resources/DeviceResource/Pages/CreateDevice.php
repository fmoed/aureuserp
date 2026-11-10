<?php

namespace Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Tables\DevicesTable;
use Webkul\BiometricAttendance\Models\Device;

class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label(__('biometric-attendance::filament/resources/device.table.actions.test-connection.label'))
                ->icon('heroicon-o-signal')
                ->color('info')
                ->visible(fn (): bool => DeviceResource::canCreate())
                ->authorize(fn (): bool => DeviceResource::canCreate())
                ->action(function (): void {
                    $data = $this->form->getRawState();

                    if (blank($data['host'] ?? null)) {
                        Notification::make()
                            ->danger()
                            ->title(__('biometric-attendance::filament/resources/device.table.actions.test-connection.notification.missing-host'))
                            ->send();

                        return;
                    }

                    $device = new Device([
                        'name'     => $data['name'] ?? null,
                        'host'     => trim((string) $data['host']),
                        'port'     => (int) ($data['port'] ?? 4370),
                        'protocol' => (string) ($data['protocol'] ?? 'tcp'),
                        'comm_key' => (int) ($data['comm_key'] ?? 0),
                    ]);

                    DevicesTable::probeConnection($device);
                }),
        ];
    }
}
