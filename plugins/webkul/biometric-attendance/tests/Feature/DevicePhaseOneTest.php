<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\BiometricAttendance\BiometricAttendanceServiceProvider;
use Webkul\BiometricAttendance\Console\ReadAttendanceCommand;
use Webkul\BiometricAttendance\Console\TestConnectionCommand;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Services\DeviceInfo;
use Webkul\BiometricAttendance\Services\PhpZktecoClient;
use Webkul\BiometricAttendance\Services\PunchRecord;
use Webkul\BiometricAttendance\Services\ZktecoConnectionException;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('biometric-attendance');
});

it('loads the plugin provider and binds the client contract', function () {
    expect(app()->getProvider(BiometricAttendanceServiceProvider::class))->not->toBeNull()
        ->and(app(ZktecoClientInterface::class))->toBeInstanceOf(PhpZktecoClient::class);
});

it('registers the biometric artisan commands', function () {
    expect(Artisan::all('biometric:test'))->toHaveKey('biometric:test')
        ->and(Artisan::all('biometric:read'))->toHaveKey('biometric:read');

    expect(new TestConnectionCommand)->toBeInstanceOf(Command::class)
        ->and(new ReadAttendanceCommand)->toBeInstanceOf(Command::class);
});

it('creates a device configuration', function () {
    $device = Device::create([
        'name'      => 'Main Gate UA300',
        'host'      => '192.168.1.201',
        'port'      => 4370,
        'protocol'  => 'tcp',
        'comm_key'  => 0,
        'timezone'  => 'Asia/Riyadh',
        'is_active' => true,
    ]);

    expect($device->exists)->toBeTrue()
        ->and($device->port)->toBe(4370)
        ->and($device->is_active)->toBeTrue();
});

it('parses device info and punch records without hardware', function () {
    $info = new DeviceInfo(
        deviceName: 'UA300',
        serialNumber: 'SN123',
        firmware: 'Ver 6.60',
        platform: 'ZMM220_TFT',
        vendorName: 'ZKTeco Inc.',
        osVersion: '1.0',
        deviceTime: '2026-09-20 08:00:00',
        host: '192.168.1.201',
    );

    expect($info->toArray())->toMatchArray([
        'device_name'   => 'UA300',
        'serial_number' => 'SN123',
        'firmware'      => 'Ver 6.60',
    ]);

    $record = PunchRecord::fromArray([
        'uid'         => 1,
        'user_id'     => 7,
        'state'       => 1,
        'record_time' => '2026-09-20 08:03:11',
        'type'        => 0,
        'device_ip'   => '192.168.1.201',
    ]);

    expect($record->toArray())->toBe([
        'uid'         => '1',
        'user_id'     => '7',
        'state'       => '1',
        'record_time' => '2026-09-20 08:03:11',
        'type'        => '0',
        'device_ip'   => '192.168.1.201',
    ]);
});

it('tests connection through a mocked client', function () {
    $device = Device::create([
        'name'     => 'Mocked Device',
        'host'     => '10.0.0.9',
        'timezone' => 'UTC',
    ]);

    app()->bind(ZktecoClientInterface::class, fn () => new class implements ZktecoClientInterface
    {
        public function getDeviceInfo(Device $device): DeviceInfo
        {
            return new DeviceInfo(deviceName: 'UA300', serialNumber: 'MOCK-SN', host: $device->host);
        }

        public function getAttendanceLogs(Device $device): array
        {
            return [
                PunchRecord::fromArray(['uid' => 1, 'user_id' => '7', 'state' => '1', 'record_time' => '2026-09-20 08:03:11', 'type' => '0', 'device_ip' => $device->host]),
                PunchRecord::fromArray(['uid' => 2, 'user_id' => '7', 'state' => '1', 'record_time' => '2026-09-20 17:05:44', 'type' => '1', 'device_ip' => $device->host]),
            ];
        }
    });

    $this->artisan('biometric:test', ['device' => $device->id])
        ->expectsOutputToContain('UA300')
        ->assertSuccessful();

    expect($device->fresh()->last_error)->toBeNull()
        ->and($device->fresh()->last_sync_at)->not->toBeNull();

    $this->artisan('biometric:read', ['device' => $device->id, '--limit' => 10])
        ->expectsOutputToContain('Total records on device: 2')
        ->expectsOutputToContain('08:03:11')
        ->assertSuccessful();
});

it('handles connection failure safely', function () {
    $device = Device::create([
        'name'     => 'Unreachable Device',
        'host'     => '192.0.2.1',
        'timezone' => 'UTC',
    ]);

    app()->bind(ZktecoClientInterface::class, fn () => new class implements ZktecoClientInterface
    {
        public function getDeviceInfo(Device $device): DeviceInfo
        {
            throw new ZktecoConnectionException('Cannot reach device [192.0.2.1:4370] (tcp).');
        }

        public function getAttendanceLogs(Device $device): array
        {
            throw new ZktecoConnectionException('Cannot reach device [192.0.2.1:4370] (tcp).');
        }
    });

    $this->artisan('biometric:test', ['device' => $device->id])
        ->assertFailed();

    expect($device->fresh()->last_error)->toContain('Cannot reach device');

    $this->artisan('biometric:read', ['device' => $device->id])
        ->assertFailed();
});

it('rejects an unknown device identifier', function () {
    $this->artisan('biometric:test', ['device' => 'no-such-device'])
        ->assertFailed();
});

it('classifies a closed TCP port as a network failure with the real client', function () {
    $device = Device::make([
        'name'     => 'Closed Port',
        'host'     => '127.0.0.1',
        'port'     => 1,
        'protocol' => 'tcp',
        'timezone' => 'UTC',
    ]);

    try {
        (new PhpZktecoClient)->getDeviceInfo($device);

        $this->fail('Expected a ZktecoConnectionException.');
    } catch (ZktecoConnectionException $e) {
        expect($e->getMessage())->toContain('network layer failed');
    }
});

it('prints connection stages with the debug flag', function () {
    $device = Device::create([
        'name'     => 'Debug Device',
        'host'     => '127.0.0.1',
        'port'     => 1,
        'protocol' => 'tcp',
        'timezone' => 'UTC',
    ]);

    app()->bind(ZktecoClientInterface::class, PhpZktecoClient::class);

    $this->artisan('biometric:test', ['device' => $device->id, '--debug' => true])
        ->expectsOutputToContain('probing TCP')
        ->assertFailed();

    expect($device->fresh()->last_error)->toContain('network layer failed');
});

it('ships the plugin card icon used by the plugins list', function () {
    expect(file_exists(public_path('svg/biometric-attendance.svg')))->toBeTrue()
        ->and(file_exists(base_path('resources/svg/biometric-attendance.svg')))->toBeTrue();
});

it('lives in the attendance configurations cluster', function () {
    expect(DeviceResource::getCluster())->toBe(Configurations::class)
        ->and(DeviceResource::getSlug())->toBe('devices');
});

it('installs the attendance layer as a dependency', function () {
    TestBootstrapHelper::ensurePluginInstalled('biometric-attendance');

    expect(Schema::hasTable('attendance_events'))->toBeTrue();
});
