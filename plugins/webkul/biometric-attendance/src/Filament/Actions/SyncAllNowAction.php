<?php

namespace Webkul\BiometricAttendance\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Webkul\BiometricAttendance\Jobs\SyncAllDevicesJob;
use Webkul\Support\Services\CompanyContext;

class SyncAllNowAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'syncAllNow';
    }

    public static function canRunSync(): bool
    {
        return (bool) (auth()->user()?->can('update_biometric_attendance_device')
            || auth()->user()?->can('page_biometric_attendance_manage_sync'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('biometric-attendance::filament/actions/sync-all-now.label'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->authorize(fn (): bool => static::canRunSync())
            ->requiresConfirmation()
            ->action(function (): void {
                $companyIds = app(CompanyContext::class)->activeIds();

                SyncAllDevicesJob::dispatch(
                    $companyIds === [] ? null : $companyIds,
                    auth()->id(),
                );

                Notification::make()
                    ->info()
                    ->title(__('biometric-attendance::filament/actions/sync-all-now.notification.queued-title'))
                    ->body(__('biometric-attendance::filament/actions/sync-all-now.notification.queued-body'))
                    ->send();
            });
    }
}
