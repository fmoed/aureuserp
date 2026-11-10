<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

use Illuminate\Support\Carbon;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Services\EventPairingService;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\RelationManagers\PunchesRelationManager;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\BiometricAttendance\Services\DeviceInfo;
use Webkul\BiometricAttendance\Services\SyncPipeline;
use Webkul\BiometricAttendance\Services\ZktecoConnectionException;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('biometric-attendance');
    TestBootstrapHelper::ensurePluginInstalled('attendance');
});

function backfillDevice(string $name, ?int $companyId = null): Device
{
    return Device::create([
        'name'       => $name,
        'host'       => '10.10.0.'.random_int(2, 250),
        'timezone'   => 'UTC',
        'is_active'  => true,
        'company_id' => $companyId,
    ]);
}

function backfillPunch(Device $device, string $userId, string $at, int $uid): Punch
{
    return Punch::create([
        'device_id'  => $device->id,
        'zk_user_id' => $userId,
        'punch_time' => $at,
        'raw_state'  => 1,
        'raw_type'   => 0,
        'device_uid' => $uid,
    ]);
}

function backfillShiftEmployee(Calendar $calendar, string $barcode = ''): Employee
{
    return Employee::factory()->create([
        'name'        => 'Backfill Employee',
        'time_zone'   => 'UTC',
        'calendar_id' => $calendar->id,
        'barcode'     => $barcode !== '' ? $barcode : null,
    ]);
}

function backfillCalendar(): Calendar
{
    $calendar = Calendar::factory()->create([
        'name'      => 'Backfill Shift',
        'timezone'  => 'UTC',
        'is_active' => true,
    ]);

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        CalendarAttendance::create([
            'name'        => "{$day} shift",
            'day_of_week' => $day,
            'day_period'  => 'morning',
            'calendar_id' => $calendar->id,
            'hour_from'   => '08:00',
            'hour_to'     => '17:00',
        ]);
    }

    return $calendar;
}

it('recovers historical orphan punches when the employee barcode is saved', function () {
    $device = backfillDevice('Backfill Gate');
    $calendar = backfillCalendar();
    $employee = backfillShiftEmployee($calendar);

    $uid = 0;

    foreach (range(1, 5) as $day) {
        $date = sprintf('2026-09-%02d', $day);

        backfillPunch($device, '00501', $date.' 08:00:00', ++$uid);
        backfillPunch($device, '00501', $date.' 17:00:00', ++$uid);
    }

    expect(Punch::whereNull('employee_id')->count())->toBe(10);

    $employee->barcode = '501';
    $employee->save();

    expect(Punch::whereNull('employee_id')->count())->toBe(0)
        ->and(Punch::where('employee_id', $employee->id)->count())->toBe(10)
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(5);
});

it('matches and pairs stored punches even when every device is offline', function () {
    $device = backfillDevice('Offline Gate');
    $calendar = backfillCalendar();
    $employee = backfillShiftEmployee($calendar, '777');

    // Bypass the save listener so the punches stay orphan until the sync.
    backfillPunch($device, '777', '2026-09-01 08:00:00', 1);
    backfillPunch($device, '777', '2026-09-01 17:00:00', 2);
    backfillPunch($device, '777', '2026-09-02 08:00:00', 3);
    backfillPunch($device, '777', '2026-09-02 17:00:00', 4);

    expect(Punch::whereNull('employee_id')->count())->toBe(4);

    app()->bind(ZktecoClientInterface::class, fn () => new class implements ZktecoClientInterface
    {
        public function getDeviceInfo(Device $device): DeviceInfo
        {
            throw new ZktecoConnectionException('Device unreachable');
        }

        public function getAttendanceLogs(Device $device): array
        {
            throw new ZktecoConnectionException('Device unreachable');
        }
    });

    $summary = app(SyncPipeline::class)->syncAll(chain: true);

    expect($summary['failed'])->toBeGreaterThanOrEqual(1)
        ->and(Punch::whereNull('employee_id')->count())->toBe(0)
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(2);
});

it('rebuilds automatic rows for a new shift without touching manual rows', function () {
    $calendar = backfillCalendar();
    $employee = backfillShiftEmployee($calendar);

    $manual = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-01',
        'check_in'    => '2026-09-01 08:00:00',
        'check_out'   => '2026-09-01 17:00:00',
        'source'      => 'manual',
    ]);

    Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-02',
        'check_in'    => '2026-09-02 08:00:00',
        'check_out'   => '2026-09-02 17:00:00',
        'source'      => 'biometric-attendance:1',
    ]);

    AttendanceEvent::create([
        'employee_id' => $employee->id,
        'punched_at'  => '2026-09-02 08:00:00',
        'direction'   => 1,
        'source'      => 'biometric-attendance:1',
        'source_ref'  => 'punch:backfill-1',
    ]);

    AttendanceEvent::create([
        'employee_id' => $employee->id,
        'punched_at'  => '2026-09-02 17:00:00',
        'direction'   => -1,
        'source'      => 'biometric-attendance:1',
        'source_ref'  => 'punch:backfill-2',
    ]);

    $result = app(EventPairingService::class)->rebuildForRange(
        $employee,
        'biometric-attendance',
        Carbon::parse('2026-09-01'),
        Carbon::parse('2026-09-30'),
    );

    expect($result['rows'])->toBe(1)
        ->and($manual->refresh()->getRawOriginal('check_out'))->toBe('2026-09-01 17:00:00')
        ->and(Attendance::where('employee_id', $employee->id)->where('source', 'manual')->count())->toBe(1)
        ->and(Attendance::where('employee_id', $employee->id)->where('work_date', '2026-09-02')->where('source', 'biometric-attendance:1')->count())->toBe(1);
});

it('registers the unmatched punches relation manager on the device resource', function () {
    expect(DeviceResource::getRelations())->toContain(PunchesRelationManager::class);
});
