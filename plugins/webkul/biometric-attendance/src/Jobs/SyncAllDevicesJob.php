<?php

namespace Webkul\BiometricAttendance\Jobs;

use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Webkul\BiometricAttendance\Services\SyncPipeline;
use Webkul\Security\Models\User;

class SyncAllDevicesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    /**
     * @param  list<int>|null  $companyIds
     */
    public function __construct(
        public ?array $companyIds = null,
        public ?int $initiatorUserId = null,
    ) {}

    public function uniqueId(): string
    {
        if ($this->companyIds === null || $this->companyIds === []) {
            return 'biometric:sync-all-job:all';
        }

        $ids = $this->companyIds;
        sort($ids);

        return 'biometric:sync-all-job:'.implode(',', $ids);
    }

    public function handle(SyncPipeline $pipeline): void
    {
        $lock = Cache::lock('biometric:sync-all', 600);

        if (! $lock->get()) {
            return;
        }

        try {
            $summary = $pipeline->syncAll(
                chain: true,
                companyIds: ($this->companyIds === [] ? null : $this->companyIds),
            );
        } finally {
            $lock->release();
        }

        if ($this->initiatorUserId !== null) {
            $user = User::query()->find($this->initiatorUserId);

            if ($user !== null) {
                $notification = ($summary['failed'] ?? 0) > 0
                    ? Notification::make()
                        ->danger()
                        ->title(__('biometric-attendance::filament/actions/sync-all-now.notification.failed-title'))
                    : Notification::make()
                        ->success()
                        ->title(__('biometric-attendance::filament/actions/sync-all-now.notification.title'))
                        ->body(__('biometric-attendance::filament/actions/sync-all-now.notification.body'));

                $notification->sendToDatabase($user);
            }
        }
    }
}
