<?php

namespace Webkul\BiometricAttendance\Console;

use Illuminate\Console\Command;
use Throwable;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Services\DeviceSyncService;
use Webkul\BiometricAttendance\Services\ZktecoConnectionException;

class SyncAllCommand extends Command
{
    protected $signature = 'biometric:sync-all';

    protected $description = 'Sync attendance logs from all active devices (one offline device never blocks the rest)';

    public function handle(DeviceSyncService $service): int
    {
        $devices = Device::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($devices->isEmpty()) {
            $this->warn('No active devices found.');

            return self::SUCCESS;
        }

        $stored = 0;
        $skipped = 0;
        $backfilled = 0;
        $failed = 0;

        foreach ($devices as $device) {
            try {
                $result = $service->sync($device);
            } catch (ZktecoConnectionException $e) {
                $device->update(['last_error' => $e->getMessage()]);

                $failed++;

                $this->error("[{$device->name}] {$e->getMessage()}");

                continue;
            } catch (Throwable $e) {
                $device->update(['last_error' => $e->getMessage()]);

                $failed++;

                $this->error("[{$device->name}] {$e->getMessage()}");

                continue;
            }

            $stored += $result['stored'];
            $skipped += $result['skipped'];
            $backfilled += $result['backfilled'];

            $this->info("[{$device->name}] stored: {$result['stored']}, already present: {$result['skipped']}, backfilled: {$result['backfilled']}.");
        }

        $succeeded = $devices->count() - $failed;

        $this->info("Synced {$succeeded}/{$devices->count()} devices — stored: {$stored}, already present: {$skipped}, backfilled: {$backfilled}, failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
