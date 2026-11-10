<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/CompanyHelper.php';

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Attendance\Enums\BreakPolicy;
use Webkul\Attendance\Enums\EarlyArrivalPolicy;
use Webkul\Attendance\Enums\HolidayPolicy;
use Webkul\Attendance\Enums\MissingCheckoutPolicy;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;
use Webkul\Support\Models\CalendarLeave;
use Webkul\TimeOff\Enums\RequestDateFromPeriod;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveMandatoryDay;
use Webkul\TimeOff\Models\LeaveType;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');
    TestBootstrapHelper::ensurePluginInstalled('time-off');
});

/**
 * Weekdays split in two work periods (08:00-12:00, 13:00-17:00) so the
 * roster itself implies a one-hour lunch break: 480 scheduled minutes.
 */
function hoursCalendar(array $attributes = []): Calendar
{
    $calendar = Calendar::factory()->create(array_merge([
        'name'      => 'Hours Shift',
        'timezone'  => 'UTC',
        'is_active' => true,
    ], $attributes));

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
        foreach ([['morning', '08:00', '12:00'], ['afternoon', '13:00', '17:00']] as [$period, $from, $to]) {
            CalendarAttendance::create([
                'name'        => "{$day} {$period}",
                'day_of_week' => $day,
                'day_period'  => $period,
                'calendar_id' => $calendar->id,
                'hour_from'   => $from,
                'hour_to'     => $to,
            ]);
        }
    }

    return $calendar;
}

function hoursEmployee(Calendar $calendar, string $name = 'Hours Employee'): Employee
{
    return Employee::factory()->create([
        'name'        => $name,
        'user_id'     => User::factory(),
        'time_zone'   => 'UTC',
        'calendar_id' => $calendar->id,
    ]);
}

function hoursRow(Employee $employee, string $date, string $in, ?string $out): Attendance
{
    return Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => $date,
        'check_in'    => $date.' '.$in.':00',
        'check_out'   => $out === null ? null : $date.' '.$out.':00',
        'source'      => 'manual',
    ]);
}

function hoursLeaveType(bool $unpaid = false): LeaveType
{
    return LeaveType::create([
        'name'                  => $unpaid ? 'Unpaid Hours Leave' : 'Paid Hours Leave',
        'leave_validation_type' => 'both',
        'unpaid'                => $unpaid,
    ]);
}

function hoursLeave(Employee $employee, LeaveType $type, string $date, array $extra = []): Leave
{
    return Leave::create(array_merge([
        'employee_id'       => $employee->id,
        'holiday_status_id' => $type->id,
        'date_from'         => $date.' 00:00:00',
        'date_to'           => $date.' 00:00:00',
        'request_date_from' => $date,
        'request_date_to'   => $date,
        'state'             => State::VALIDATE_TWO,
        'company_id'        => $employee->company_id,
    ], $extra));
}

function hoursEntries(Employee $employee, string $from, string $to): Collection
{
    return app(AttendanceOverviewService::class)
        ->daily(Employee::whereKey($employee->id)->get(), Carbon::parse($from), Carbon::parse($to))
        ->keyBy('date');
}

it('reports regular, late and overtime hours for standard shift days', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $employee = hoursEmployee(hoursCalendar());

    hoursRow($employee, '2026-10-05', '08:00', '17:00');
    hoursRow($employee, '2026-10-06', '08:30', '17:00');
    hoursRow($employee, '2026-10-09', '08:00', '19:00');

    $entries = hoursEntries($employee, '2026-10-05', '2026-10-09');

    expect($entries['2026-10-05']['status'])->toBe('present')
        ->and($entries['2026-10-05']['scheduled_minutes'])->toBe(480)
        ->and($entries['2026-10-05']['minutes'])->toBe(480)
        ->and($entries['2026-10-05']['regular_minutes'])->toBe(480)
        ->and($entries['2026-10-05']['overtime_minutes'])->toBe(0)
        ->and($entries['2026-10-05']['break_minutes'])->toBe(60)
        ->and($entries['2026-10-05']['late_minutes'])->toBe(0)
        ->and($entries['2026-10-05']['early_leave_minutes'])->toBe(0)
        ->and($entries['2026-10-05']['variance_minutes'])->toBe(0)
        ->and($entries['2026-10-06']['minutes'])->toBe(450)
        ->and($entries['2026-10-06']['late_minutes'])->toBe(30)
        ->and($entries['2026-10-06']['late_count'])->toBe(1)
        ->and($entries['2026-10-06']['variance_minutes'])->toBe(-30)
        ->and($entries['2026-10-06']['status'])->toBe('present')
        ->and($entries['2026-10-09']['minutes'])->toBe(600)
        ->and($entries['2026-10-09']['regular_minutes'])->toBe(480)
        ->and($entries['2026-10-09']['overtime_minutes'])->toBe(120)
        ->and($entries['2026-10-09']['variance_minutes'])->toBe(120);

    $summary = app(AttendanceOverviewService::class)->summary(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-10-05'), Carbon::parse('2026-10-09')
    )->first();

    expect($summary['scheduled_minutes'])->toBe(2400)
        ->and($summary['minutes'])->toBe(1530)
        ->and($summary['regular_minutes'])->toBe(1410)
        ->and($summary['overtime_minutes'])->toBe(120)
        ->and($summary['break_minutes'])->toBe(180)
        ->and($summary['late_minutes'])->toBe(30)
        ->and($summary['variance_minutes'])->toBe(90)
        ->and($summary['hours_compliance_rate'])->toBe(58.8);
});

it('clamps or offsets an early arrival according to the shift policy', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $clamped = hoursEmployee(hoursCalendar(['early_arrival_policy' => EarlyArrivalPolicy::ClampToSchedule]));
    hoursRow($clamped, '2026-10-05', '07:00', '16:00');

    $offset = hoursEmployee(hoursCalendar(['early_arrival_policy' => EarlyArrivalPolicy::OffsetEarlyDeparture]));
    hoursRow($offset, '2026-10-05', '07:00', '16:00');

    $clampedDay = hoursEntries($clamped, '2026-10-05', '2026-10-05')['2026-10-05'];
    $offsetDay = hoursEntries($offset, '2026-10-05', '2026-10-05')['2026-10-05'];

    expect($clampedDay['minutes'])->toBe(420)
        ->and($clampedDay['early_leave_minutes'])->toBe(60)
        ->and($clampedDay['variance_minutes'])->toBe(-60)
        ->and($offsetDay['minutes'])->toBe(480)
        ->and($offsetDay['early_leave_minutes'])->toBe(0)
        ->and($offsetDay['variance_minutes'])->toBe(0);
});

it('counts skipping the scheduled break as full hours when leaving early', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $employee = hoursEmployee(hoursCalendar(['break_policy' => BreakPolicy::AllowSkipForEarlyExit]));
    hoursRow($employee, '2026-10-05', '08:00', '16:00');

    $day = hoursEntries($employee, '2026-10-05', '2026-10-05')['2026-10-05'];

    expect($day['minutes'])->toBe(480)
        ->and($day['early_leave_minutes'])->toBe(0)
        ->and($day['variance_minutes'])->toBe(0);
});

it('applies the missing checkout policy to an incomplete day', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $absentEmployee = hoursEmployee(hoursCalendar([
        'missing_checkout_policy' => MissingCheckoutPolicy::IncompleteAbsent,
    ]), 'Missing Checkout Absent');
    hoursRow($absentEmployee, '2026-10-05', '08:00', null);

    $creditEmployee = hoursEmployee(hoursCalendar([
        'missing_checkout_policy' => MissingCheckoutPolicy::AutoCreditScheduled,
    ]), 'Missing Checkout Credit');
    hoursRow($creditEmployee, '2026-10-05', '08:00', null);

    $absentDay = hoursEntries($absentEmployee, '2026-10-05', '2026-10-05')['2026-10-05'];
    $creditDay = hoursEntries($creditEmployee, '2026-10-05', '2026-10-05')['2026-10-05'];

    $absentSummary = app(AttendanceOverviewService::class)->summaryFor(
        $absentEmployee->refresh(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05')
    );

    $creditSummary = app(AttendanceOverviewService::class)->summaryFor(
        $creditEmployee->refresh(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05')
    );

    expect($absentDay['status'])->toBe('missing_checkout')
        ->and($absentDay['is_missing_checkout'])->toBeTrue()
        ->and($absentSummary['missing_checkouts'])->toBe(1)
        ->and($absentSummary['absent'])->toBe(1)
        ->and($creditDay['status'])->toBe('present')
        ->and($creditDay['is_estimated'])->toBeTrue()
        ->and($creditDay['minutes'])->toBe(480)
        ->and($creditSummary['absent'])->toBe(0)
        ->and($creditSummary['present'])->toBe(1);
});

it('records weekend work without inflating the workday denominator', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $employee = hoursEmployee(hoursCalendar(), 'Weekend Worker');
    hoursRow($employee, '2026-10-11', '08:00', '13:00');

    $service = app(AttendanceOverviewService::class);

    $entries = $service->daily(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-10-10'), Carbon::parse('2026-10-11')
    )->keyBy('date');

    $summary = $service->summary(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-10-10'), Carbon::parse('2026-10-11')
    )->first();

    $row = $service->register(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-10-10'), Carbon::parse('2026-10-11')
    )->first();

    expect($entries['2026-10-11']['status'])->toBe('weekend_present')
        ->and($entries['2026-10-11']['minutes'])->toBe(300)
        ->and($summary['workdays'])->toBe(0)
        ->and($summary['absent'])->toBe(0)
        ->and($summary['minutes'])->toBe(300)
        ->and($summary['overtime_minutes'])->toBe(300)
        ->and($summary['weekend_worked_days'])->toBe(1)
        ->and($row['cells']['2026-10-11'])->toBe('weekend_present')
        ->and($row['totals']['minutes'])->toBe(300)
        ->and($row['totals']['workdays'])->toBe(0);
});

it('records holiday work distinctly from absence on the holiday', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $employee = hoursEmployee(hoursCalendar(), 'Holiday Worker');

    CalendarLeave::create([
        'name'      => 'National Day',
        'time_type' => 'leave',
        'date_from' => '2026-10-05 00:00:00',
        'date_to'   => '2026-10-05 23:59:59',
    ]);

    hoursRow($employee, '2026-10-05', '08:00', '13:00');

    $service = app(AttendanceOverviewService::class);

    $entries = $service->daily(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05')
    )->keyBy('date');

    $summary = $service->summary(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05')
    )->first();

    expect($entries['2026-10-05']['status'])->toBe('holiday_present')
        ->and($entries['2026-10-05']['scheduled_minutes'])->toBe(0)
        ->and($entries['2026-10-05']['minutes'])->toBeGreaterThan(0)
        ->and($summary['holiday_worked_days'])->toBe(1)
        ->and($summary['workdays'])->toBe(0)
        ->and($summary['absent'])->toBe(0);
});

it('intersects half-day leave with the worked half', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $calendar = hoursCalendar();
    $type = hoursLeaveType();

    $worker = hoursEmployee($calendar, 'Half Day Worker');
    hoursRow($worker, '2026-10-05', '13:00', '17:00');
    hoursLeave($worker, $type, '2026-10-05', [
        'request_unit_half'        => true,
        'request_date_from_period' => RequestDateFromPeriod::MORNING,
    ]);

    $absentee = hoursEmployee($calendar, 'Half Day Absentee');
    hoursLeave($absentee, $type, '2026-10-05', [
        'request_unit_half'        => true,
        'request_date_from_period' => RequestDateFromPeriod::MORNING,
    ]);

    $service = app(AttendanceOverviewService::class);

    $workedDay = $service->daily(
        Employee::whereKey($worker->id)->get(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05')
    )->first();

    $workedSummary = $service->summaryFor($worker->refresh(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05'));
    $absentSummary = $service->summaryFor($absentee->refresh(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05'));

    expect($workedDay['status'])->toBe('half_day')
        ->and($workedDay['scheduled_minutes'])->toBe(240)
        ->and($workedDay['minutes'])->toBe(240)
        ->and($workedDay['late_minutes'])->toBe(0)
        ->and($workedDay['present_weight'])->toBe(0.5)
        ->and($workedDay['leave_weight'])->toBe(0.5)
        ->and($workedDay['absent_weight'])->toBe(0.0)
        ->and($workedSummary['present'])->toBe(0.5)
        ->and($workedSummary['on_leave'])->toBe(0.5)
        ->and($workedSummary['absent'])->toBe(0)
        ->and($absentSummary['present'])->toBe(0)
        ->and($absentSummary['on_leave'])->toBe(0.5)
        ->and($absentSummary['absent'])->toBe(0.5);
});

it('subtracts an approved hourly permission from the schedule', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $employee = hoursEmployee(hoursCalendar());
    $type = hoursLeaveType();

    hoursRow($employee, '2026-10-05', '10:00', '17:00');
    hoursLeave($employee, $type, '2026-10-05', [
        'request_unit_hours' => true,
        'request_hour_from'  => '08:00',
        'request_hour_to'    => '10:00',
        'number_of_hours'    => 2,
    ]);

    $day = hoursEntries($employee, '2026-10-05', '2026-10-05')['2026-10-05'];

    expect($day['status'])->toBe('present')
        ->and($day['scheduled_minutes'])->toBe(360)
        ->and($day['minutes'])->toBe(360)
        ->and($day['late_minutes'])->toBe(0);
});

it('separates paid and unpaid leave in the summary', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $calendar = hoursCalendar();

    $paidEmployee = hoursEmployee($calendar, 'Paid Leave Employee');
    hoursLeave($paidEmployee, hoursLeaveType(false), '2026-10-05');

    $unpaidEmployee = hoursEmployee($calendar, 'Unpaid Leave Employee');
    hoursLeave($unpaidEmployee, hoursLeaveType(true), '2026-10-05');

    $service = app(AttendanceOverviewService::class);

    $paid = $service->summaryFor($paidEmployee->refresh(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05'));
    $unpaid = $service->summaryFor($unpaidEmployee->refresh(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-05'));

    expect($paid['paid_leave_days'])->toBe(1)
        ->and($paid['unpaid_leave_days'])->toBe(0)
        ->and($paid['paid_leave_minutes'])->toBe(480)
        ->and($unpaid['paid_leave_days'])->toBe(0)
        ->and($unpaid['unpaid_leave_days'])->toBe(1)
        ->and($unpaid['unpaid_leave_minutes'])->toBe(480)
        ->and($unpaid['absent'])->toBe(0);
});

it('respects the shift holiday policy and mandatory days policy', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $workRequired = hoursEmployee(hoursCalendar(['holiday_policy' => HolidayPolicy::WorkRequired]), 'Holiday Required');

    CalendarLeave::create([
        'name'      => 'Emergency Day',
        'time_type' => 'leave',
        'date_from' => '2026-10-05 00:00:00',
        'date_to'   => '2026-10-05 23:59:59',
    ]);

    $workDay = hoursEntries($workRequired, '2026-10-05', '2026-10-05')['2026-10-05'];

    expect($workDay['status'])->toBe('absent');

    $mandatoryEmployee = hoursEmployee(hoursCalendar(), 'Mandatory Worker');

    LeaveMandatoryDay::create([
        'name'       => 'Inventory Sunday',
        'start_date' => '2026-10-11 00:00:00',
        'end_date'   => '2026-10-11 23:59:59',
    ]);

    DB::table('settings')
        ->where('group', 'attendance')
        ->where('name', 'mandatory_days_policy')
        ->update(['payload' => json_encode('treat_as_mandatory_workday')]);

    app(AttendanceSettings::class)->refresh();

    try {
        $service = app(AttendanceOverviewService::class);

        $entries = $service->daily(
            Employee::whereKey($mandatoryEmployee->id)->get(),
            Carbon::parse('2026-10-11'), Carbon::parse('2026-10-11')
        )->keyBy('date');

        $summary = $service->summary(
            Employee::whereKey($mandatoryEmployee->id)->get(),
            Carbon::parse('2026-10-11'), Carbon::parse('2026-10-11')
        )->first();

        expect($entries['2026-10-11']['status'])->toBe('absent')
            ->and($summary['workdays'])->toBe(1)
            ->and($summary['absent'])->toBe(1);
    } finally {
        DB::table('settings')
            ->where('group', 'attendance')
            ->where('name', 'mandatory_days_policy')
            ->update(['payload' => json_encode('treat_as_holiday')]);

        app(AttendanceSettings::class)->refresh();
    }
});

it('does not count incomplete weekend punches as absences and caps hours when overtime is disabled', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $noOvertimeShift = hoursCalendar(['overtime_enabled' => false]);
    $employee = hoursEmployee($noOvertimeShift, 'No Overtime Worker');

    // Worked 14 hours on Monday on a shift with overtime disabled: capped at 480m, 0 overtime, 0 variance
    hoursRow($employee, '2026-10-05', '08:00', '22:00');

    // Incomplete single punch on Saturday (weekend): must stay weekly_off, 0 absent, 0 missing checkouts
    hoursRow($employee, '2026-10-10', '09:00', null);

    // Complete punch on Sunday (weekend) when overtime is disabled: ignored, stays weekly_off
    hoursRow($employee, '2026-10-11', '09:00', '14:00');

    $service = app(AttendanceOverviewService::class);

    $summary = $service->summary(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-10-05'), Carbon::parse('2026-10-11')
    )->first();

    $register = $service->register(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-10-05'), Carbon::parse('2026-10-11')
    )->first();

    expect($summary['overtime_minutes'])->toBe(0)
        ->and($summary['minutes'])->toBe(480)
        ->and($summary['variance_minutes'])->toBe(0)
        ->and($summary['missing_checkouts'])->toBe(0)
        ->and($summary['weekend_worked_days'])->toBe(0)
        ->and($register['cells']['2026-10-10'])->toBe('weekly_off')
        ->and($register['cells']['2026-10-11'])->toBe('weekly_off');
});
