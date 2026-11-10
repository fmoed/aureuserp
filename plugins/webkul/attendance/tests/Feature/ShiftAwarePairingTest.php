<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

use Illuminate\Support\Facades\DB;
use Webkul\Attendance\Enums\PairingMode;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Services\EventPairingService;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');

    WriterRegistry::register('biometric-attendance');
});

function pairingCalendar(string $from, string $to, array $attributes = []): Calendar
{
    $calendar = Calendar::factory()->create(array_merge([
        'name'      => 'Pairing '.$from.'-'.$to,
        'timezone'  => 'UTC',
        'is_active' => true,
    ], $attributes));

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        CalendarAttendance::create([
            'name'        => "{$day} shift",
            'day_of_week' => $day,
            'day_period'  => 'morning',
            'calendar_id' => $calendar->id,
            'hour_from'   => $from,
            'hour_to'     => $to,
        ]);
    }

    return $calendar;
}

function pairingEmployee(Calendar $calendar): Employee
{
    return Employee::factory()->create([
        'name'        => 'Pairing Employee '.$calendar->id,
        'time_zone'   => 'UTC',
        'calendar_id' => $calendar->id,
    ]);
}

function pushPunch(Employee $employee, string $punchedAt, int $direction = AttendanceEvent::DIRECTION_UNKNOWN, string $ref = ''): AttendanceEvent
{
    static $sequence = 0;

    $sequence++;

    return AttendanceEvent::create([
        'employee_id' => $employee->id,
        'punched_at'  => $punchedAt,
        'direction'   => $direction,
        'source'      => 'biometric-attendance:1',
        'source_ref'  => $ref !== '' ? $ref : 'punch:'.$sequence,
    ]);
}

it('does not let a missed checkout swallow the next morning check-in', function () {
    $calendar = pairingCalendar('09:00', '17:00');
    $employee = pairingEmployee($calendar);

    pushPunch($employee, '2026-10-05 16:30:00');
    pushPunch($employee, '2026-10-06 08:00:00');

    app(EventPairingService::class)->rebuild($employee, 'biometric-attendance');

    $rows = Attendance::query()->where('employee_id', $employee->id)->orderBy('work_date')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->work_date->toDateString())->toBe('2026-10-05')
        ->and($rows[0]->check_in->format('H:i'))->toBe('16:30')
        ->and($rows[0]->check_out)->toBeNull()
        ->and($rows[1]->work_date->toDateString())->toBe('2026-10-06')
        ->and($rows[1]->check_in->format('H:i'))->toBe('08:00')
        ->and($rows[1]->check_out)->toBeNull();
});

it('keeps an orphan out punch separate from the next day check-in', function () {
    $calendar = pairingCalendar('09:00', '17:00');
    $employee = pairingEmployee($calendar);

    pushPunch($employee, '2026-10-05 17:00:00', AttendanceEvent::DIRECTION_OUT);
    pushPunch($employee, '2026-10-06 08:00:00', AttendanceEvent::DIRECTION_IN);

    app(EventPairingService::class)->rebuild($employee, 'biometric-attendance');

    $rows = Attendance::query()->where('employee_id', $employee->id)->orderBy('work_date')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->work_date->toDateString())->toBe('2026-10-05')
        ->and($rows[0]->check_out)->toBeNull()
        ->and($rows[1]->check_in->format('H:i'))->toBe('08:00');
});

it('keeps a night shift with break punches on one logical workday', function () {
    $calendar = pairingCalendar('22:00', '04:00');
    $employee = pairingEmployee($calendar);

    pushPunch($employee, '2026-10-05 22:00:00', AttendanceEvent::DIRECTION_IN);
    pushPunch($employee, '2026-10-06 01:00:00', AttendanceEvent::DIRECTION_OUT);
    pushPunch($employee, '2026-10-06 01:30:00', AttendanceEvent::DIRECTION_IN);
    pushPunch($employee, '2026-10-06 04:00:00', AttendanceEvent::DIRECTION_OUT);

    app(EventPairingService::class)->rebuild($employee, 'biometric-attendance');

    $rows = Attendance::query()->where('employee_id', $employee->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->work_date->toDateString())->toBe('2026-10-05')
        ->and($rows[0]->check_in->format('H:i'))->toBe('22:00')
        ->and($rows[0]->check_out->format('H:i'))->toBe('04:00')
        ->and($rows[0]->break_minutes)->toBe(30);
});

it('splits every in-out pair while keeping the logical workday', function () {
    $calendar = pairingCalendar('22:00', '04:00', ['pairing_mode' => PairingMode::EveryInOutPair]);
    $employee = pairingEmployee($calendar);

    pushPunch($employee, '2026-10-05 22:00:00', AttendanceEvent::DIRECTION_IN);
    pushPunch($employee, '2026-10-06 01:00:00', AttendanceEvent::DIRECTION_OUT);
    pushPunch($employee, '2026-10-06 01:30:00', AttendanceEvent::DIRECTION_IN);
    pushPunch($employee, '2026-10-06 04:00:00', AttendanceEvent::DIRECTION_OUT);

    app(EventPairingService::class)->rebuild($employee, 'biometric-attendance');

    $rows = Attendance::query()->where('employee_id', $employee->id)->orderBy('check_in')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->work_date->toDateString())->toBe('2026-10-05')
        ->and($rows[1]->work_date->toDateString())->toBe('2026-10-05')
        ->and($rows[0]->check_out->format('H:i'))->toBe('01:00')
        ->and($rows[1]->check_in->format('H:i'))->toBe('01:30');
});

it('leaves locked workdays untouched while rebuilding later days', function () {
    $calendar = pairingCalendar('09:00', '17:00');
    $employee = pairingEmployee($calendar);

    DB::table('settings')
        ->where('group', 'attendance')
        ->where('name', 'attendance_lock_date')
        ->update(['payload' => json_encode('2026-10-05')]);

    app(AttendanceSettings::class)->refresh();

    expect(AttendanceSettings::lockDate()?->toDateString())->toBe('2026-10-05');

    pushPunch($employee, '2026-10-05 09:00:00');
    pushPunch($employee, '2026-10-06 09:00:00');

    app(EventPairingService::class)->rebuild($employee, 'biometric-attendance');

    $rows = Attendance::query()->where('employee_id', $employee->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->work_date->toDateString())->toBe('2026-10-06');
});

it('preserves check-out and avoids turning work hours into break when three IN punches arrive', function () {
    $calendar = pairingCalendar('07:00', '15:00');
    $employee = pairingEmployee($calendar);

    pushPunch($employee, '2026-11-01 07:16:43', AttendanceEvent::DIRECTION_IN);
    pushPunch($employee, '2026-11-01 07:26:43', AttendanceEvent::DIRECTION_IN);
    pushPunch($employee, '2026-11-01 12:44:44', AttendanceEvent::DIRECTION_IN);

    app(EventPairingService::class)->rebuild($employee, 'biometric-attendance');

    $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($row->check_in->format('H:i:s'))->toBe('07:16:43')
        ->and($row->check_out?->format('H:i:s'))->toBe('12:44:44')
        ->and($row->break_minutes)->toBe(0);
});
