<?php

namespace Webkul\BiometricAttendance\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Webkul\BiometricAttendance\Services\AttendanceBridge;

class SyncAllNowAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'syncAllNow';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('biometric-attendance::filament/actions/sync-all-now.label'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->action(function (): void {
                $exit = Artisan::call('biometric:sync-all');

                if ($exit === 0) {
                    Artisan::call('biometric:push-events');

                    if (AttendanceBridge::isAvailable()) {
                        Artisan::call('attendance:process-events');
                    }
                }

                if ($exit !== 0) {
                    Notification::make()
                        ->danger()
                        ->title(__('biometric-attendance::filament/actions/sync-all-now.notification.failed-title'))
                        ->body(trim(Artisan::output()) ?: null)
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('biometric-attendance::filament/actions/sync-all-now.notification.title'))
                    ->body(__('biometric-attendance::filament/actions/sync-all-now.notification.body'))
                    ->send();
            });
    }
}
