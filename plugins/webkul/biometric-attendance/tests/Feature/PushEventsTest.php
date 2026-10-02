<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Webkul\Attendance\Events\EventGateway;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\BiometricAttendance\Console\PushEventsCommand;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\Employee\Models\Employee;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('biometric-attendance');
});

function ensureAttendanceAvailable(object $test): void
{
    if (! class_exists(EventGateway::class) || ! class_exists(AttendanceEvent::class)) {
        $test->markTestSkipped('Attendance plugin is not installed in this checkout.');
    }

    TestBootstrapHelper::ensurePluginInstalled('attendance');

    if (class_exists(WriterRegistry::class) && ! WriterRegistry::isRegistered('biometric-attendance')) {
        WriterRegistry::register('biometric-attendance', fn (?int $id): ?string => Device::find($id)?->name);
    }
}

it('registers the push command', function () {
    expect(Artisan::all('biometric:push-events'))->toHaveKey('biometric:push-events')
        ->and(new PushEventsCommand)->toBeInstanceOf(Command::class);
});

it('skips unmatched punches and picks them up after backfill', function () {
    ensureAttendanceAvailable($this);

    $device = Device::create([
        'name'     => 'Backfill Gate',
        'host'     => '10.0.0.11',
        'timezone' => 'UTC',
    ]);

    $punch = Punch::create([
        'device_id'  => $device->id,
        'zk_user_id' => '777',
        'punch_time' => '2026-10-01 08:00:00',
        'raw_state'  => 0,
        'raw_type'   => 0,
        'device_uid' => 1,
    ]);

    $this->artisan('biometric:push-events')->assertSuccessful();

    expect(AttendanceEvent::count())->toBe(0);

    $employee = Employee::factory()->create(['barcode' => '777', 'time_zone' => 'UTC']);

    $punch->update(['employee_id' => $employee->id]);

    $this->artisan('biometric:push-events')->assertSuccessful();

    expect(AttendanceEvent::count())->toBe(1)
        ->and(AttendanceEvent::first()->source)->toBe("biometric-attendance:{$device->id}")
        ->and(AttendanceEvent::first()->source_ref)->toBe("punch:{$punch->id}")
        ->and(AttendanceEvent::first()->source_label)->toBe('Backfill Gate');
});

it('stores one event for double pushes of the same punch', function () {
    ensureAttendanceAvailable($this);

    $employee = Employee::factory()->create(['barcode' => '778', 'time_zone' => 'UTC']);

    $device = Device::create([
        'name'     => 'Rerun Gate',
        'host'     => '10.0.0.12',
        'timezone' => 'UTC',
    ]);

    $punch = Punch::create([
        'device_id'   => $device->id,
        'zk_user_id'  => '778',
        'employee_id' => $employee->id,
        'punch_time'  => '2026-10-01 08:00:00',
        'raw_state'   => 0,
        'raw_type'    => 0,
        'device_uid'  => 1,
    ]);

    $this->artisan('biometric:push-events')->assertSuccessful();
    $this->artisan('biometric:push-events')->assertSuccessful();

    expect(AttendanceEvent::where('source', "biometric-attendance:{$device->id}")->where('source_ref', "punch:{$punch->id}")->count())->toBe(1);
});

it('maps raw states to attendance directions', function () {
    ensureAttendanceAvailable($this);

    $employee = Employee::factory()->create(['barcode' => '779', 'time_zone' => 'UTC']);

    $device = Device::create([
        'name'     => 'Direction Gate',
        'host'     => '10.0.0.13',
        'timezone' => 'UTC',
    ]);

    foreach ([0, 1, 7] as $index => $rawState) {
        Punch::create([
            'device_id'   => $device->id,
            'zk_user_id'  => '779',
            'employee_id' => $employee->id,
            'punch_time'  => "2026-10-01 08:0{$index}:00",
            'raw_state'   => $rawState,
            'raw_type'    => 0,
            'device_uid'  => 10 + $index,
        ]);
    }

    $this->artisan('biometric:push-events')->assertSuccessful();

    $directions = AttendanceEvent::where('source', "biometric-attendance:{$device->id}")->orderBy('punched_at')->pluck('direction')->all();

    expect($directions)->toBe([1, -1, 0]);
});

it('converts device wall-clock times to UTC using the device timezone', function () {
    ensureAttendanceAvailable($this);

    $employee = Employee::factory()->create(['barcode' => '780', 'time_zone' => 'UTC']);

    $device = Device::create([
        'name'     => 'Riyadh Gate',
        'host'     => '10.0.0.14',
        'timezone' => 'Asia/Riyadh',
    ]);

    $punch = Punch::create([
        'device_id'   => $device->id,
        'zk_user_id'  => '780',
        'employee_id' => $employee->id,
        'punch_time'  => '2026-10-01 12:00:00',
        'raw_state'   => 0,
        'raw_type'    => 0,
        'device_uid'  => 1,
    ]);

    $this->artisan('biometric:push-events')->assertSuccessful();

    $event = AttendanceEvent::where('source', "biometric-attendance:{$device->id}")->where('source_ref', "punch:{$punch->id}")->first();

    expect($event->getRawOriginal('punched_at'))->toBe('2026-10-01 09:00:00');
});

it('pairs an overnight shift pushed from punches into one row anchored to its start day', function () {
    ensureAttendanceAvailable($this);

    $employee = Employee::factory()->create(['barcode' => '781', 'time_zone' => 'UTC']);

    $device = Device::create([
        'name'     => 'Night Gate',
        'host'     => '10.0.0.15',
        'timezone' => 'UTC',
    ]);

    Punch::create([
        'device_id'   => $device->id,
        'zk_user_id'  => '781',
        'employee_id' => $employee->id,
        'punch_time'  => '2026-10-01 22:00:00',
        'raw_state'   => 0,
        'raw_type'    => 0,
        'device_uid'  => 1,
    ]);

    Punch::create([
        'device_id'   => $device->id,
        'zk_user_id'  => '781',
        'employee_id' => $employee->id,
        'punch_time'  => '2026-10-02 06:00:00',
        'raw_state'   => 1,
        'raw_type'    => 0,
        'device_uid'  => 2,
    ]);

    $this->artisan('biometric:push-events')->assertSuccessful();

    Artisan::call('attendance:process-events');

    $rows = Attendance::where('employee_id', $employee->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->work_date->format('Y-m-d'))->toBe('2026-10-01')
        ->and($rows->first()->getRawOriginal('check_in'))->toBe('2026-10-01 22:00:00')
        ->and($rows->first()->getRawOriginal('check_out'))->toBe('2026-10-02 06:00:00');
});
