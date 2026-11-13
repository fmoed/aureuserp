<?php

namespace Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Throwable;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Policies\DevicePolicy;
use Webkul\BiometricAttendance\Services\SyncPipeline;

class DevicesTable
{
    /**
     * Probe a device and report the outcome. Works for an unsaved instance
     * (create page: nothing is persisted) and for a saved record (status is
     * stamped on it).
     */
    public static function probeConnection(Device $device, ?Device $statusTarget = null): void
    {
        $target = $statusTarget ?? ($device->exists ? $device : null);

        try {
            $info = app(ZktecoClientInterface::class)->getDeviceInfo($device);
        } catch (Throwable $e) {
            if ($target?->exists) {
                $target->update(['last_error' => $e->getMessage()]);
            }

            Notification::make()
                ->danger()
                ->title(__('biometric-attendance::filament/resources/device.table.actions.test-connection.notification.failed-title'))
                ->body($e->getMessage())
                ->send();

            return;
        }

        if ($target?->exists) {
            $target->update(['last_sync_at' => now(), 'last_error' => null]);
        }

        Notification::make()
            ->success()
            ->title(__('biometric-attendance::filament/resources/device.table.actions.test-connection.notification.title'))
            ->body(($info->deviceName ?? $device->host).' · '.($info->serialNumber ?? '—'))
            ->send();
    }

    public static function makeTestConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label(__('biometric-attendance::filament/resources/device.table.actions.test-connection.label'))
            ->icon('heroicon-o-signal')
            ->color('info')
            ->authorize(fn (?Device $record): bool => $record !== null
                ? DeviceResource::canEdit($record)
                : DeviceResource::canCreate())
            ->action(function (?Device $record, Component $livewire): void {
                if ($livewire instanceof CreateRecord || $livewire instanceof EditRecord) {
                    $data = $livewire->form->getRawState();
                    $host = array_key_exists('host', $data) ? trim((string) $data['host']) : ($record?->host ?? '');

                    if (blank($host)) {
                        Notification::make()
                            ->danger()
                            ->title(__('biometric-attendance::filament/resources/device.table.actions.test-connection.notification.missing-host'))
                            ->send();

                        return;
                    }

                    $probe = new Device([
                        'name'     => $data['name'] ?? $record?->name,
                        'host'     => $host,
                        'port'     => (int) ($data['port'] ?? $record?->port ?? 4370),
                        'protocol' => (string) ($data['protocol'] ?? $record?->protocol ?? 'tcp'),
                        'comm_key' => filled($data['comm_key'] ?? null)
                            ? (int) $data['comm_key']
                            : (int) ($record?->comm_key ?? 0),
                    ]);

                    static::probeConnection($probe, $record);

                    return;
                }

                if ($record !== null) {
                    static::probeConnection($record);
                }
            });
    }

    public static function makeSyncNowAction(): Action
    {
        return Action::make('syncNow')
            ->label(__('biometric-attendance::filament/resources/device.table.actions.sync-now.label'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->authorize(fn (Device $record): bool => DeviceResource::canEdit($record))
            ->action(function (Device $record): void {
                try {
                    $result = app(SyncPipeline::class)->syncDevice($record);
                } catch (Throwable $e) {
                    $record->update(['last_error' => $e->getMessage()]);

                    Notification::make()
                        ->danger()
                        ->title(__('biometric-attendance::filament/resources/device.table.actions.sync-now.notification.failed-title'))
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                if (($result['push_failed'] ?? 0) > 0) {
                    Notification::make()
                        ->warning()
                        ->title(__('biometric-attendance::filament/resources/device.table.actions.sync-now.notification.partial-title'))
                        ->body(__('biometric-attendance::filament/resources/device.table.actions.sync-now.notification.partial-body', [
                            'stored'     => $result['stored'],
                            'skipped'    => $result['skipped'],
                            'backfilled' => $result['backfilled'],
                            'failed'     => $result['push_failed'],
                        ]))
                        ->send();

                    return;
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
            });
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => DevicePolicy::scopeQuery($query)->with('company'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('biometric-attendance::filament/resources/device.table.columns.name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('company.name')
                    ->label(__('biometric-attendance::filament/resources/device.table.columns.company'))
                    ->sortable()
                    ->toggleable(),
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
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_error')
                    ->label(__('biometric-attendance::filament/resources/device.table.columns.last-error'))
                    ->limit(40)
                    ->color('danger')
                    ->tooltip(fn (Device $record): ?string => $record->last_error ?: null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('biometric-attendance::filament/resources/device.table.columns.created-at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label(__('biometric-attendance::filament/resources/device.table.filters.company'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label(__('biometric-attendance::filament/resources/device.table.filters.active')),
            ])
            ->recordUrl(fn (Device $record): string => DeviceResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    static::makeTestConnectionAction(),
                    static::makeSyncNowAction(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
