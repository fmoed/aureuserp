<?php

namespace Webkul\BiometricAttendance\Console;

use Illuminate\Console\Command;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Services\ZktecoConnectionException;

class ReadAttendanceCommand extends Command
{
    protected $signature = 'biometric:read {device : Device ID or name} {--limit=10 : Show only the first N records}';

    protected $description = 'Read raw attendance logs from a ZKTeco device and print them (read-only on the device; updates the device sync status)';

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

        $this->info("Reading attendance logs from [{$device->host}:{$device->port}] via {$device->protocol} ...");

        try {
            $records = $client->getAttendanceLogs($device);
        } catch (ZktecoConnectionException $e) {
            $device->update(['last_error' => $e->getMessage()]);

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $device->update(['last_sync_at' => now(), 'last_error' => null]);

        $this->info('Total records on device: '.count($records));

        $limit = max(0, (int) $this->option('limit'));

        $sample = array_slice($records, 0, $limit);

        if ($sample === []) {
            $this->warn('No attendance records returned by the device.');

            return self::SUCCESS;
        }

        $this->table(
            ['uid', 'user_id', 'state', 'record_time', 'type', 'device_ip'],
            array_map(fn ($record): array => array_values($record->toArray()), $sample)
        );

        return self::SUCCESS;
    }
}
