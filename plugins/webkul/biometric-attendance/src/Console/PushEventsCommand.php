<?php

namespace Webkul\BiometricAttendance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Webkul\BiometricAttendance\Services\AttendanceBridge;
use Webkul\BiometricAttendance\Services\SyncPipeline;

class PushEventsCommand extends Command
{
    protected $signature = 'biometric:push-events';

    protected $description = 'Push matched biometric punches into attendance events (rerun-safe, no pairing here)';

    public function handle(SyncPipeline $pipeline): int
    {
        if (! AttendanceBridge::isAvailable()) {
            $this->error('Attendance plugin is not installed. Skipping push.');

            return self::FAILURE;
        }

        $lock = Cache::lock('biometric:push-events', 300);

        if (! $lock->get()) {
            $this->warn('Another biometric push is already running. Skipping.');

            return self::SUCCESS;
        }

        try {
            $result = $pipeline->pushPendingPunches();
        } finally {
            $lock->release();
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        $this->info("Pushed {$result['pushed']} punches to attendance events.");

        return self::SUCCESS;
    }
}
