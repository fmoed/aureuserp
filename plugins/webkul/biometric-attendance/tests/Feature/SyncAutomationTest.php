<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Webkul\Attendance\Events\EventGateway;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\BiometricAttendance\Console\SyncAllCommand;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\BiometricAttendance\Services\DeviceInfo;
use Webkul\BiometricAttendance\Services\PunchRecord;
use Webkul\BiometricAttendance\Services\ZktecoConnectionException;
use Webkul\Employee\Models\Employee;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('biometric-attendance');
});

function bindWatermarkSyncClient(): void
{
    app()->bind(ZktecoClientInterface::class, fn () => new class implements ZktecoClientInterface
    {
        public function getDeviceInfo(Device $device): DeviceInfo
        {
            return new DeviceInfo(deviceName: 'UA300', serialNumber: 'WM-SN', host: $device->host);
        }

        public function getAttendanceLogs(Device $device): array
        {
            return [
                PunchRecord::fromArray(['uid' => 1, 'user_id' => '111', 'state' => '1', 'record_time' => '2026-09-22 09:30:52', 'type' => '0', 'device_ip' => $device->host]),
                PunchRecord::fromArray(['uid' => 2, 'user_id' => '102', 'state' => '1', 'record_time' => '2026-09-22 19:14:40', 'type' => '0', 'device_ip' => $device->host]),
            ];
        }
    });
}

function bindFlakySyncClient(): void
{
    app()->bind(ZktecoClientInterface::class, fn () => new class implements ZktecoClientInterface
    {
        public function getDeviceInfo(Device $device): DeviceInfo
        {
            return new DeviceInfo(deviceName: 'UA300', serialNumber: 'FLAKY-SN', host: $device->host);
        }

        public function getAttendanceLogs(Device $device): array
        {
            if ($device->host === '192.0.2.99') {
                throw new ZktecoConnectionException('Cannot reach device [192.0.2.99:4370] (tcp).');
            }

            return [
                PunchRecord::fromArray(['uid' => 1, 'user_id' => '111', 'state' => '1', 'record_time' => '2026-09-22 09:30:52', 'type' => '0', 'device_ip' => $device->host]),
                PunchRecord::fromArray(['uid' => 2, 'user_id' => '102', 'state' => '1', 'record_time' => '2026-09-22 19:14:40', 'type' => '0', 'device_ip' => $device->host]),
            ];
        }
    });
}

/**
 * @param  list<array{uid: int, user_id: string, record_time: string}>  $rows
 */
function bindClearedLogClient(array $rows): void
{
    app()->bind(ZktecoClientInterface::class, fn () => new class($rows) implements ZktecoClientInterface
    {
        /**
         * @param  list<array{uid: int, user_id: string, record_time: string}>  $rows
         */
        public function __construct(private array $rows) {}

        public function getDeviceInfo(Device $device): DeviceInfo
        {
            return new DeviceInfo(deviceName: 'UA300', serialNumber: 'CLEAR-SN', host: $device->host);
        }

        public function getAttendanceLogs(Device $device): array
        {
            return array_map(
                fn (array $row): PunchRecord => PunchRecord::fromArray([...$row, 'state' => '1', 'type' => '0', 'device_ip' => $device->host]),
                $this->rows
            );
        }
    });
}

function skipUnlessAttendanceIsPresent(object $test): void
{
    if (! class_exists(EventGateway::class) || ! class_exists(AttendanceEvent::class)) {
        $test->markTestSkipped('Attendance plugin is not installed in this checkout.');
    }

    TestBootstrapHelper::ensurePluginInstalled('attendance');
}

it('registers the sync-all command', function () {
    expect(Artisan::all('biometric:sync-all'))->toHaveKey('biometric:sync-all')
        ->and(new SyncAllCommand)->toBeInstanceOf(Command::class);
});

it('stores nothing on the second sync because the watermark skips known uids', function () {
    $device = Device::create([
        'name'     => 'Watermark Device',
        'host'     => '10.0.0.21',
        'timezone' => 'UTC',
    ]);

    bindWatermarkSyncClient();

    $this->artisan('biometric:sync', ['device' => $device->id])
        ->expectsOutputToContain('stored: 2, already present: 0')
        ->assertSuccessful();

    expect($device->fresh()->last_sync_uid)->toBe(2);

    $this->artisan('biometric:sync', ['device' => $device->id])
        ->expectsOutputToContain('stored: 0, already present: 2')
        ->assertSuccessful();

    expect(Punch::where('device_id', $device->id)->count())->toBe(2)
        ->and($device->fresh()->last_sync_uid)->toBe(2);
});

it('pushes nothing on the second push because pushed punches are filtered out', function () {
    skipUnlessAttendanceIsPresent($this);

    $employee = Employee::factory()->create(['barcode' => '782', 'time_zone' => 'UTC']);

    $device = Device::create([
        'name'     => 'Receipt Device',
        'host'     => '10.0.0.22',
        'timezone' => 'UTC',
    ]);

    $punch = Punch::create([
        'device_id'   => $device->id,
        'zk_user_id'  => '782',
        'employee_id' => $employee->id,
        'punch_time'  => '2026-10-01 08:00:00',
        'raw_state'   => 0,
        'raw_type'    => 0,
        'device_uid'  => 1,
    ]);

    $this->artisan('biometric:push-events')
        ->expectsOutputToContain('Pushed 1')
        ->assertSuccessful();

    expect($punch->fresh()->pushed_at)->not->toBeNull()
        ->and(AttendanceEvent::where('source_ref', "punch:{$punch->id}")->count())->toBe(1);

    $this->artisan('biometric:push-events')
        ->expectsOutputToContain('Pushed 0')
        ->assertSuccessful();

    expect(AttendanceEvent::where('source_ref', "punch:{$punch->id}")->count())->toBe(1);
});

it('syncs the healthy device and still fails when one device is offline', function () {
    $healthy = Device::create([
        'name'      => 'Gate A',
        'host'      => '10.0.0.23',
        'timezone'  => 'UTC',
        'is_active' => true,
    ]);

    $offline = Device::create([
        'name'      => 'Gate B',
        'host'      => '192.0.2.99',
        'timezone'  => 'UTC',
        'is_active' => true,
    ]);

    bindFlakySyncClient();

    $this->artisan('biometric:sync-all')
        ->expectsOutputToContain('[Gate A] stored: 2')
        ->expectsOutputToContain('failed: 1')
        ->assertFailed();

    expect(Punch::where('device_id', $healthy->id)->count())->toBe(2)
        ->and(Punch::where('device_id', $offline->id)->count())->toBe(0)
        ->and($healthy->fresh()->last_sync_at)->not->toBeNull()
        ->and($healthy->fresh()->last_error)->toBeNull()
        ->and($offline->fresh()->last_error)->toContain('Cannot reach device');
});

it('rescans the whole log when the device log was cleared', function () {
    $device = Device::create(['name' => 'Cleared Device', 'host' => '10.0.0.22', 'timezone' => 'UTC']);

    bindWatermarkSyncClient();

    $this->artisan('biometric:sync', ['device' => $device->id])->assertSuccessful();

    bindClearedLogClient([
        ['uid' => 1, 'user_id' => '111', 'record_time' => '2026-09-25 08:00:00'],
        ['uid' => 2, 'user_id' => '102', 'record_time' => '2026-09-25 08:05:00'],
        ['uid' => 3, 'user_id' => '111', 'record_time' => '2026-09-25 17:00:00'],
    ]);

    $this->artisan('biometric:sync', ['device' => $device->id])
        ->expectsOutputToContain('stored: 3, already present: 0')
        ->assertSuccessful();

    expect(Punch::where('device_id', $device->id)->count())->toBe(5)
        ->and($device->fresh()->last_sync_uid)->toBe(3);
});

it('keeps one punch when the same record returns under a new uid', function () {
    $device = Device::create(['name' => 'Reused Uid Device', 'host' => '10.0.0.23', 'timezone' => 'UTC']);

    bindClearedLogClient([['uid' => 1, 'user_id' => '111', 'record_time' => '2026-09-22 09:30:52']]);

    $this->artisan('biometric:sync', ['device' => $device->id])->assertSuccessful();

    bindClearedLogClient([['uid' => 7, 'user_id' => '111', 'record_time' => '2026-09-22 09:30:52']]);

    $this->artisan('biometric:sync', ['device' => $device->id])
        ->expectsOutputToContain('stored: 0, already present: 1')
        ->assertSuccessful();

    expect(Punch::where('device_id', $device->id)->count())->toBe(1);
});
