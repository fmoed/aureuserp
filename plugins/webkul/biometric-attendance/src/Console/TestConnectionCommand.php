<?php

namespace Webkul\BiometricAttendance\Console;

use Illuminate\Console\Command;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Services\PhpZktecoClient;
use Webkul\BiometricAttendance\Services\ZktecoConnectionException;

class TestConnectionCommand extends Command
{
    protected $signature = 'biometric:test {device : Device ID or name} {--debug : Show every connection stage and timings}';

    protected $description = 'Test TCP/UDP connection to a ZKTeco device and show device information (read-only)';

    public function handle(ZktecoClientInterface $client): int
    {
        $device = $this->findDevice($this->argument('device'));

        if (! $device) {
            $this->error('Device ['.$this->argument('device').'] not found.');

            return self::FAILURE;
        }

        $this->info("Connecting to [{$device->host}:{$device->port}] via {$device->protocol} ...");

        if ($this->option('debug') && $client instanceof PhpZktecoClient) {
            $client->setProgress(fn (string $stage) => $this->line("  <comment>›</comment> {$stage}"));
        }

        try {
            $info = $client->getDeviceInfo($device);
        } catch (ZktecoConnectionException $e) {
            $device->update(['last_error' => $e->getMessage()]);

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $device->update(['last_sync_at' => now(), 'last_error' => null]);

        $this->info('Connected. Device information:');

        $this->table(
            ['Field', 'Value'],
            collect($info->toArray())->map(fn ($value, $key): array => [$key, $value ?? '—'])->values()->all()
        );

        return self::SUCCESS;
    }

    protected function findDevice(string $identifier): ?Device
    {
        return Device::query()
            ->when(is_numeric($identifier), fn ($query) => $query->where('id', $identifier))
            ->when(! is_numeric($identifier), fn ($query) => $query->where('name', $identifier))
            ->first();
    }
}
