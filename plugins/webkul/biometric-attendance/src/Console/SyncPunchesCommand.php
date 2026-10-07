<?php

namespace Webkul\BiometricAttendance\Console;

use Illuminate\Console\Command;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Services\DeviceSyncService;
use Webkul\BiometricAttendance\Services\ZktecoConnectionException;

class SyncPunchesCommand extends Command
{
    protected $signature = 'biometric:sync {device : Device ID or name}';

    protected $description = 'Download attendance logs from a ZKTeco device and store new raw punches only (no matching, no pairing)';

    public function handle(DeviceSyncService $service): int
    {
        $identifier = $this->argument('device');

        $device = Device::query()
            ->when(is_numeric($identifier), fn ($query) => $query->where('id', $identifier))
            ->when(! is_numeric($identifier), fn ($query) => $query->where('name', $identifier))
            ->first();

        if (! $device) {
            $this->error('Device ['.$this->argument('device').'] not found.');

            return self::FAILURE;
        }

        $this->info("Syncing attendance logs from [{$device->host}:{$device->port}] via {$device->protocol} ...");

        try {
            $result = $service->sync($device);
        } catch (ZktecoConnectionException $e) {
            $device->update(['last_error' => $e->getMessage()]);

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Device records: {$result['fetched']}, stored: {$result['stored']}, already present: {$result['skipped']}, backfilled: {$result['backfilled']}.");

        return self::SUCCESS;
    }
}
