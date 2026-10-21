<?php

namespace Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Services\AttendanceBridge;
use Webkul\BiometricAttendance\Services\DeviceSyncService;
use Webkul\BiometricAttendance\Services\ZktecoConnectionException;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('biometric-attendance::filament/resources/device.table.columns.name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('host')
                    ->label(__('biometric-attendance::filament/resources/device.table.columns.host'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('port')
                    ->label(__('biometric-attendance::filament/resources/device.table.columns.port'))
                    ->sortable(),
                TextColumn::make('protocol')
                    ->label(__('biometric-attendance::filament/resources/device.table.columns.protocol'))
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('biometric-attendance::filament/resources/device.table.columns.active'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('last_sync_at')
                    ->label(__('biometric-attendance::filament/resources/device.table.columns.last-sync'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('biometric-attendance::filament/resources/device.table.columns.created-at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('testConnection')
                    ->label(__('biometric-attendance::filament/resources/device.table.actions.test-connection.label'))
                    ->icon('heroicon-o-signal')
                    ->color('info')
                    ->action(function ($record) {
                        try {
                            $info = app(ZktecoClientInterface::class)->getDeviceInfo($record);
                        } catch (ZktecoConnectionException $e) {
                            $record->update(['last_error' => $e->getMessage()]);

                            Notification::make()
                                ->danger()
                                ->title(__('biometric-attendance::filament/resources/device.table.actions.test-connection.notification.failed-title'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        $record->update(['last_sync_at' => now(), 'last_error' => null]);

                        Notification::make()
                            ->success()
                            ->title(__('biometric-attendance::filament/resources/device.table.actions.test-connection.notification.title'))
                            ->body(($info->deviceName ?? $record->host).' · '.($info->serialNumber ?? '—'))
                            ->send();
                    }),
                Action::make('syncNow')
                    ->label(__('biometric-attendance::filament/resources/device.table.actions.sync-now.label'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(function ($record) {
                        try {
                            $result = app(DeviceSyncService::class)->sync($record);
                        } catch (ZktecoConnectionException $e) {
                            $record->update(['last_error' => $e->getMessage()]);

                            Notification::make()
                                ->danger()
                                ->title(__('biometric-attendance::filament/resources/device.table.actions.sync-now.notification.failed-title'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        } catch (Throwable $e) {
                            $record->update(['last_error' => $e->getMessage()]);

                            Notification::make()
                                ->danger()
                                ->title(__('biometric-attendance::filament/resources/device.table.actions.sync-now.notification.failed-title'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        Artisan::call('biometric:push-events');

                        if (AttendanceBridge::isAvailable()) {
                            Artisan::call('attendance:process-events');
                        }

                        Notification::make()
                            ->success()
                            ->title(__('biometric-attendance::filament/resources/device.table.actions.sync-now.notification.title'))
                            ->body(__('biometric-attendance::filament/resources/device.table.actions.sync-now.notification.body', [
                                'stored'     => $result['stored'],
                                'skipped'    => $result['skipped'],
                                'backfilled' => $result['backfilled'],
                            ]))
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
