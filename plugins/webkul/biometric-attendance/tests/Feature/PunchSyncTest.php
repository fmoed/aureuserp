<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

use Illuminate\Console\Command;
use Webkul\BiometricAttendance\BiometricAttendanceServiceProvider;
use Webkul\BiometricAttendance\Console\SyncPunchesCommand;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\BiometricAttendance\Services\DeviceInfo;
use Webkul\BiometricAttendance\Services\PunchRecord;
use Webkul\Employee\Models\Employee;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('biometric-attendance');
});

function bindFakeSyncClient(): void
{
    app()->bind(ZktecoClientInterface::class, fn () => new class implements ZktecoClientInterface
    {
        public function getDeviceInfo(Device $device): DeviceInfo
        {
            return new DeviceInfo(deviceName: 'UA300', serialNumber: 'SYNC-SN', host: $device->host);
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

it('registers the sync command', function () {
    expect(Artisan::all('biometric:sync'))->toHaveKey('biometric:sync')
        ->and(new SyncPunchesCommand)->toBeInstanceOf(Command::class);
});

it('stores only new raw punches without employee matching', function () {
    $device = Device::create([
        'name'     => 'Sync Device',
        'host'     => '10.0.0.9',
        'timezone' => 'UTC',
    ]);

    bindFakeSyncClient();

    $this->artisan('biometric:sync', ['device' => $device->id])
        ->expectsOutputToContain('stored: 2, already present: 0')
        ->assertSuccessful();

    expect(Punch::count())->toBe(2)
        ->and(Punch::whereNotNull('employee_id')->count())->toBe(0);

    $first = Punch::where('device_uid', 1)->first();

    expect($first->device_id)->toBe($device->id)
        ->and($first->zk_user_id)->toBe('111')
        ->and($first->raw_state)->toBe(1)
        ->and($first->raw_type)->toBe(0)
        ->and($first->punch_time->format('Y-m-d H:i:s'))->toBe('2026-09-22 09:30:52')
        ->and($device->fresh()->last_error)->toBeNull()
        ->and($device->fresh()->last_sync_at)->not->toBeNull();
});

it('skips already stored punches on repeated sync', function () {
    $device = Device::create([
        'name'     => 'Resync Device',
        'host'     => '10.0.0.9',
        'timezone' => 'UTC',
    ]);

    bindFakeSyncClient();

    $this->artisan('biometric:sync', ['device' => $device->id])->assertSuccessful();
    $this->artisan('biometric:sync', ['device' => $device->id])
        ->expectsOutputToContain('stored: 0, already present: 2')
        ->assertSuccessful();

    expect(Punch::where('device_id', $device->id)->count())->toBe(2);
});

it('rejects an unknown device identifier for sync', function () {
    $this->artisan('biometric:sync', ['device' => 'no-such-device'])
        ->assertFailed();
});

it('loads the punch relations', function () {
    $device = Device::create([
        'name'     => 'Relation Device',
        'host'     => '10.0.0.9',
        'timezone' => 'UTC',
    ]);

    bindFakeSyncClient();

    $this->artisan('biometric:sync', ['device' => $device->id])->assertSuccessful();

    $punch = Punch::with(['device', 'employee'])->first();

    expect($punch->device->is($device))->toBeTrue()
        ->and($punch->employee)->toBeNull()
        ->and(app()->getProvider(BiometricAttendanceServiceProvider::class))->not->toBeNull();
});

it('matches new punches to employees by exact barcode', function () {
    $employee = Employee::factory()->create(['barcode' => '111']);

    $device = Device::create([
        'name'     => 'Matching Device',
        'host'     => '10.0.0.9',
        'timezone' => 'UTC',
    ]);

    bindFakeSyncClient();

    $this->artisan('biometric:sync', ['device' => $device->id])
        ->expectsOutputToContain('stored: 2, already present: 0, backfilled: 0')
        ->assertSuccessful();

    expect(Punch::where('device_id', $device->id)->where('zk_user_id', '111')->value('employee_id'))->toBe($employee->id)
        ->and(Punch::where('device_id', $device->id)->where('zk_user_id', '102')->value('employee_id'))->toBeNull();
});

it('backfills previously stored unmatched punches exactly once', function () {
    $device = Device::create([
        'name'     => 'Backfill Device',
        'host'     => '10.0.0.9',
        'timezone' => 'UTC',
    ]);

    Punch::create([
        'device_id'  => $device->id,
        'zk_user_id' => '111',
        'punch_time' => '2026-09-22 09:30:52',
        'raw_state'  => 1,
        'raw_type'   => 0,
        'device_uid' => 1,
    ]);

    $employee = Employee::factory()->create(['barcode' => '111']);

    bindFakeSyncClient();

    $this->artisan('biometric:sync', ['device' => $device->id])
        ->expectsOutputToContain('stored: 1, already present: 1, backfilled: 1')
        ->assertSuccessful();

    expect(Punch::where('device_id', $device->id)->where('device_uid', 1)->value('employee_id'))->toBe($employee->id);

    $this->artisan('biometric:sync', ['device' => $device->id])
        ->expectsOutputToContain('stored: 0, already present: 2, backfilled: 0')
        ->assertSuccessful();

    expect(Punch::where('device_id', $device->id)->count())->toBe(2);
});
