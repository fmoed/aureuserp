<?php

namespace Webkul\BiometricAttendance\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
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
            ->visible(fn (): bool => static::canRunSync())
            ->authorize(fn (): bool => static::canRunSync())
            ->requiresConfirmation()
            ->action(function (): void {
                $companyIds = app(CompanyContext::class)->activeIds();

                $options = ['--chain' => true];

                if ($companyIds !== []) {
                    $options['--company'] = $companyIds;
                }

                $exit = Artisan::call('biometric:sync-all', $options);

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
