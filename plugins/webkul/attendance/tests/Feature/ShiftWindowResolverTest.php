<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

use Illuminate\Support\Carbon;
use Webkul\Attendance\Enums\BreakPolicy;
use Webkul\Attendance\Enums\EarlyArrivalPolicy;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Services\ShiftWindowResolver;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');
});

function shiftCalendar(string $from, string $to, array $attributes = [], bool $withLunch = false): Calendar
{
    $calendar = Calendar::factory()->create(array_merge([
        'name'      => 'Shift '.$from.'-'.$to,
        'timezone'  => 'UTC',
        'is_active' => true,
    ], $attributes));

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        CalendarAttendance::create([
            'name'        => "{$day} period",
            'day_of_week' => $day,
            'day_period'  => 'morning',
            'calendar_id' => $calendar->id,
            'hour_from'   => $from,
            'hour_to'     => $to,
        ]);

        if ($withLunch) {
            CalendarAttendance::create([
                'name'        => "{$day} lunch",
                'day_of_week' => $day,
                'day_period'  => 'lunch',
                'calendar_id' => $calendar->id,
                'hour_from'   => '12:00',
                'hour_to'     => '13:00',
            ]);
        }
    }

    return $calendar;
}

function shiftEmployee(Calendar $calendar): Employee
{
    return Employee::factory()->create([
        'name'        => 'Shift Employee '.$calendar->id,
        'time_zone'   => 'UTC',
        'calendar_id' => $calendar->id,
    ]);
}

it('resolves a night shift window across midnight to its opening day', function () {
    $calendar = shiftCalendar('22:00', '04:00');
    $resolver = app(ShiftWindowResolver::class);

    $windows = $resolver->windowsForDate($calendar, '2026-10-05', 'UTC');

    expect($windows)->toHaveCount(1)
        ->and($windows[0]['work_date'])->toBe('2026-10-05')
        ->and($windows[0]['shift_start']->format('Y-m-d H:i'))->toBe('2026-10-05 22:00')
        ->and($windows[0]['shift_end']->format('Y-m-d H:i'))->toBe('2026-10-06 04:00')
        ->and($windows[0]['window_open']->format('Y-m-d H:i'))->toBe('2026-10-05 20:00')
        ->and($windows[0]['window_close']->format('Y-m-d H:i'))->toBe('2026-10-06 08:00')
        ->and($windows[0]['scheduled_minutes'])->toBe(360);
});

it('assigns late night and after-midnight punches to the same logical workday', function () {
    $calendar = shiftCalendar('22:00', '04:00');
    $employee = shiftEmployee($calendar);
    $resolver = app(ShiftWindowResolver::class);

    foreach (['2026-10-05 22:00:00', '2026-10-06 00:15:00', '2026-10-06 01:00:00', '2026-10-06 01:30:00', '2026-10-06 04:00:00'] as $punch) {
        $match = $resolver->matchPunchToWindow($employee, Carbon::parse($punch, 'UTC'));

        expect($match['work_date'])->toBe('2026-10-05');
    }
});

it('assigns an early punch before midnight to the next morning shift', function () {
    $calendar = shiftCalendar('00:30', '08:30');
    $employee = shiftEmployee($calendar);
    $resolver = app(ShiftWindowResolver::class);

    $match = $resolver->matchPunchToWindow($employee, Carbon::parse('2026-10-05 23:45:00', 'UTC'));

    expect($match['work_date'])->toBe('2026-10-06')
        ->and($match['window'])->not->toBeNull();
});

it('keeps the natural end of an overnight interval at the last requested day', function () {
    $calendar = shiftCalendar('22:00', '06:00');

    $intervals = $calendar->getAttendanceIntervalsBatch(
        Carbon::parse('2026-10-05 00:00:00', 'UTC'),
        Carbon::parse('2026-10-05 23:59:59', 'UTC'),
        timezone: 'UTC',
    );

    $interval = collect($intervals)->first()[0];

    expect($interval[0]->format('Y-m-d H:i'))->toBe('2026-10-05 22:00')
        ->and($interval[1]->format('Y-m-d H:i'))->toBe('2026-10-06 06:00');
});

it('applies seasonal periods inside their date range only', function () {
    $calendar = Calendar::factory()->create([
        'name'      => 'Seasonal Shift',
        'timezone'  => 'UTC',
        'is_active' => true,
    ]);

    CalendarAttendance::create([
        'name'        => 'Ramadan',
        'day_of_week' => 'monday',
        'day_period'  => 'morning',
        'calendar_id' => $calendar->id,
        'hour_from'   => '09:00',
        'hour_to'     => '15:00',
        'date_from'   => '2026-03-01',
        'date_to'     => '2026-03-31',
    ]);

    CalendarAttendance::create([
        'name'        => 'Regular',
        'day_of_week' => 'monday',
        'day_period'  => 'morning',
        'calendar_id' => $calendar->id,
        'hour_from'   => '08:00',
        'hour_to'     => '17:00',
    ]);

    $resolver = app(ShiftWindowResolver::class);

    expect($resolver->scheduledMinutesForDay($calendar, '2026-03-02', 'UTC'))->toBe(360)
        ->and($resolver->scheduledMinutesForDay($calendar, '2026-04-06', 'UTC'))->toBe(540);
});

it('clamps early arrival to the schedule and reports the early departure', function () {
    $calendar = shiftCalendar('08:00', '16:00');
    $employee = shiftEmployee($calendar);

    $row = Attendance::factory()->create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-10-05',
        'check_in'    => '2026-10-05 07:00:00',
        'check_out'   => '2026-10-05 15:00:00',
        'source'      => 'manual',
    ]);

    $day = app(ShiftWindowResolver::class)->evaluateDay($employee, '2026-10-05', collect([$row]), true);

    expect($day['minutes'])->toBe(420)
        ->and($day['early_leave_minutes'])->toBe(60)
        ->and($day['late_minutes'])->toBe(0)
        ->and($day['variance_minutes'])->toBe(-60);
});

it('offsets an early departure with an early arrival under the sliding policy', function () {
    $calendar = shiftCalendar('08:00', '16:00', ['early_arrival_policy' => EarlyArrivalPolicy::OffsetEarlyDeparture]);
    $employee = shiftEmployee($calendar);

    $row = Attendance::factory()->create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-10-05',
        'check_in'    => '2026-10-05 07:00:00',
        'check_out'   => '2026-10-05 15:00:00',
        'source'      => 'manual',
    ]);

    $day = app(ShiftWindowResolver::class)->evaluateDay($employee, '2026-10-05', collect([$row]), true);

    expect($day['minutes'])->toBe(480)
        ->and($day['early_leave_minutes'])->toBe(0)
        ->and($day['variance_minutes'])->toBe(0);
});

it('allows skipping the lunch break to leave early when the net hours are complete', function () {
    $calendar = shiftCalendar('08:00', '17:00', ['break_policy' => BreakPolicy::AllowSkipForEarlyExit], withLunch: true);
    $employee = shiftEmployee($calendar);

    $row = Attendance::factory()->create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-10-05',
        'check_in'    => '2026-10-05 08:00:00',
        'check_out'   => '2026-10-05 16:00:00',
        'source'      => 'manual',
    ]);

    $day = app(ShiftWindowResolver::class)->evaluateDay($employee, '2026-10-05', collect([$row]), true);

    expect($day['break_minutes'])->toBe(0)
        ->and($day['minutes'])->toBe(480)
        ->and($day['early_leave_minutes'])->toBe(0)
        ->and($day['variance_minutes'])->toBe(0);
});

it('deducts the configured break on a full day', function () {
    $calendar = shiftCalendar('08:00', '17:00', withLunch: true);
    $employee = shiftEmployee($calendar);

    $row = Attendance::factory()->create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-10-05',
        'check_in'    => '2026-10-05 08:00:00',
        'check_out'   => '2026-10-05 17:00:00',
        'source'      => 'manual',
    ]);

    $day = app(ShiftWindowResolver::class)->evaluateDay($employee, '2026-10-05', collect([$row]), true);

    expect($day['scheduled_minutes'])->toBe(480)
        ->and($day['break_minutes'])->toBe(60)
        ->and($day['minutes'])->toBe(480)
        ->and($day['regular_minutes'])->toBe(480)
        ->and($day['overtime_minutes'])->toBe(0)
        ->and($day['variance_minutes'])->toBe(0);
});
