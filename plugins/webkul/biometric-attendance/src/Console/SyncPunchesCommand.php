<?php

namespace Webkul\BiometricAttendance\Console;

use Illuminate\Console\Command;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\BiometricAttendance\Services\ZktecoConnectionException;
use Webkul\Employee\Models\Employee;

class SyncPunchesCommand extends Command
{
    protected $signature = 'biometric:sync {device : Device ID or name}';

    protected $description = 'Download attendance logs from a ZKTeco device and store new raw punches only (no matching, no pairing)';

    public function handle(ZktecoClientInterface $client): int
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
            $records = $client->getAttendanceLogs($device);
        } catch (ZktecoConnectionException $e) {
            $device->update(['last_error' => $e->getMessage()]);

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $stored = 0;
        $skipped = 0;

        $employeeIds = Employee::query()
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->pluck('id', 'barcode');

        foreach ($records as $record) {
            $punch = Punch::firstOrCreate(
                [
                    'device_id'  => $device->id,
                    'device_uid' => (int) $record->uid,
                ],
                [
                    'zk_user_id'  => $record->userId,
                    'employee_id' => $employeeIds->get($record->userId),
                    'punch_time'  => $record->recordTime !== '' ? $record->recordTime : null,
                    'raw_state'   => (int) $record->state,
                    'raw_type'    => (int) $record->type,
                ]
            );

            if ($punch->wasRecentlyCreated) {
                $stored++;
            } else {
                $skipped++;
            }
        }

        $backfilled = 0;

        foreach ($employeeIds as $barcode => $employeeId) {
            $backfilled += Punch::where('device_id', $device->id)
                ->whereNull('employee_id')
                ->where('zk_user_id', $barcode)
                ->update(['employee_id' => $employeeId]);
        }

        $device->update(['last_sync_at' => now(), 'last_error' => null]);

        $this->info('Device records: '.count($records).", stored: {$stored}, already present: {$skipped}, backfilled: {$backfilled}.");

        return self::SUCCESS;
    }
}
