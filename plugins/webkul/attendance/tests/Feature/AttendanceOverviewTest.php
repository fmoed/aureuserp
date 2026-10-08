<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Webkul\Attendance\Filament\Exports\AbsenceReportExporter;
use Webkul\Attendance\Filament\Pages\AttendanceOverview;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');
    TestBootstrapHelper::ensurePluginInstalled('time-off');
});

/**
 * Panel pages go through panel authorization, which requires an active
 * user (unlike resource policy tests). Real admins are always active.
 */
function actingAsOverviewUser(array $permissions = ['page_attendance_attendance_overview']): void
{
    FilamentHelper::actingAs($permissions)->forceFill(['is_active' => true])->saveQuietly();
}

function createWeekCalendar(?int $companyId = null): Calendar
{
    $calendar = Calendar::factory()->create([
        'timezone'   => 'UTC',
        'company_id' => $companyId,
    ]);

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
        foreach ([['morning', 8, 12], ['afternoon', 13, 17]] as [$period, $from, $to]) {
            CalendarAttendance::create([
                'name'         => "{$day} {$period}",
                'day_of_week'  => $day,
                'day_period'   => $period,
                'calendar_id'  => $calendar->id,
                'hour_from'    => $from,
                'hour_to'      => $to,
            ]);
        }
    }

    return $calendar;
}

function createOverviewEmployee(string $name, Calendar $calendar, int $companyId): Employee
{
    return Employee::factory()->create([
        'name'        => $name,
        'user_id'     => User::factory(),
        'time_zone'   => 'UTC',
        'calendar_id' => $calendar->id,
        'company_id'  => $companyId,
    ]);
}

function createLeaveType(string $validation, int $companyId, string $name = 'Test Leave'): LeaveType
{
    return LeaveType::create([
        'name'                  => $name,
        'leave_validation_type' => $validation,
        'company_id'            => $companyId,
    ]);
}

function createLeave(Employee $employee, LeaveType $type, string $from, string $to, State $state, array $extra = []): Leave
{
    return Leave::create(array_merge([
        'employee_id'       => $employee->id,
        'holiday_status_id' => $type->id,
        'date_from'         => $from.' 00:00:00',
        'date_to'           => $to.' 00:00:00',
        'request_date_from' => $from,
        'request_date_to'   => $to,
        'state'             => $state,
        'company_id'        => $employee->company_id,
    ], $extra));
}

/**
 * Single login per test: a second login breaks Spatie permission checks in
 * this harness, so the dataset never authenticates — callers log in first.
 */
function overviewDataset(?Company $company = null): array
{
    $company ??= CompanyHelper::company();

    $calendar = createWeekCalendar($company->id);

    $empA = createOverviewEmployee('Overview Emp A', $calendar, $company->id);
    $empB = createOverviewEmployee('Overview Emp B', $calendar, $company->id);

    Attendance::create([
        'employee_id' => $empA->id,
        'work_date'   => '2026-09-07',
        'check_in'    => '2026-09-07 08:00:00',
        'check_out'   => '2026-09-07 17:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
    ]);

    $annual = createLeaveType('both', $company->id, 'Annual Test Leave');
    $sick = createLeaveType('both', $company->id, 'Sick Test Leave');

    createLeave($empA, $annual, '2026-09-09', '2026-09-09', State::VALIDATE_TWO);
    createLeave($empA, $annual, '2026-09-11', '2026-09-11', State::VALIDATE_TWO, ['request_unit_half' => true]);
    createLeave($empB, $sick, '2026-09-08', '2026-09-08', State::VALIDATE_ONE);

    CalendarLeave::create([
        'name'        => 'National Test Day',
        'date_from'   => '2026-09-10 00:00:00',
        'date_to'     => '2026-09-10 23:59:59',
        'calendar_id' => null,
        'company_id'  => null,
    ]);

    return compact('company', 'calendar', 'empA', 'empB', 'annual', 'sick');
}

function overviewServiceDataset(): array
{
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    return overviewDataset($company);
}

function overviewPageDataset(): array
{
    actingAsOverviewUser();

    return overviewDataset(Company::whereKey(current_company_id())->firstOrFail());
}

it('classifies a known week into present, absent, on-leave and holiday days', function () {
    ['empA' => $empA] = overviewServiceDataset();

    $service = app(AttendanceOverviewService::class);

    $entries = $service->daily(
        Employee::whereKey($empA->id)->get(),
        Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')
    )->keyBy('date');

    expect($entries->keys()->all())->toBe(['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11'])
        ->and($entries['2026-09-07']['status'])->toBe('present')
        ->and($entries['2026-09-07']['minutes'])->toBe(540)
        ->and($entries['2026-09-08']['status'])->toBe('absent')
        ->and($entries['2026-09-09']['status'])->toBe('on_leave')
        ->and($entries['2026-09-09']['leave_label'])->toBe('Annual Test Leave')
        ->and($entries['2026-09-10']['status'])->toBe('holiday')
        ->and($entries['2026-09-10']['leave_label'])->toBe('National Test Day');
});

it('counts a pending leave as absent with a distinct badge', function () {
    ['empB' => $empB] = overviewServiceDataset();

    $service = app(AttendanceOverviewService::class);

    $entries = $service->daily(
        Employee::whereKey($empB->id)->get(),
        Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')
    )->keyBy('date');

    expect($entries['2026-09-08']['status'])->toBe('on_leave_pending')
        ->and($entries['2026-09-08']['leave_label'])->toBe('Sick Test Leave');

    $summary = $service->summary(
        Employee::whereKey($empB->id)->get(),
        Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')
    )->first();

    expect($summary['absent'])->toBe(4)
        ->and($summary['pending'])->toBe(1);
});

it('counts no-validation leave types from confirm without approval', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $calendar = createWeekCalendar($company->id);
    $employee = createOverviewEmployee('Overview Emp NV', $calendar, $company->id);

    $type = createLeaveType('no_validation', $company->id, 'Day Off');

    createLeave($employee, $type, '2026-09-08', '2026-09-08', State::CONFIRM);

    $service = app(AttendanceOverviewService::class);

    $entries = $service->daily(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')
    )->keyBy('date');

    expect($entries['2026-09-08']['status'])->toBe('on_leave');
});

it('flags half-day leaves distinctly while counting the full day', function () {
    ['empA' => $empA] = overviewServiceDataset();

    $service = app(AttendanceOverviewService::class);

    $entries = $service->daily(
        Employee::whereKey($empA->id)->get(),
        Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')
    )->keyBy('date');

    expect($entries['2026-09-11']['status'])->toBe('on_leave')
        ->and($entries['2026-09-11']['half_day'])->toBeTrue()
        ->and($entries['2026-09-09']['half_day'])->toBeFalse();
});

it('keeps presence when a punch row and an approved leave share the day', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $calendar = createWeekCalendar($company->id);
    $employee = createOverviewEmployee('Overview Emp C', $calendar, $company->id);

    Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-07',
        'check_in'    => '2026-09-07 08:00:00',
        'check_out'   => '2026-09-07 17:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
    ]);

    $type = createLeaveType('both', $company->id, 'Annual Test Leave');

    createLeave($employee, $type, '2026-09-07', '2026-09-07', State::VALIDATE_TWO);

    $service = app(AttendanceOverviewService::class);

    $entries = $service->daily(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')
    )->keyBy('date');

    expect($entries['2026-09-07']['status'])->toBe('present')
        ->and($entries['2026-09-07']['leave_label'])->toBe('Annual Test Leave');
});

it('summarizes counts and the absence rate for the week', function () {
    ['empA' => $empA] = overviewServiceDataset();

    $service = app(AttendanceOverviewService::class);

    $summary = $service->summary(
        Employee::whereKey($empA->id)->get(),
        Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')
    )->first();

    expect($summary['workdays'])->toBe(5)
        ->and($summary['present'])->toBe(1)
        ->and($summary['absent'])->toBe(1)
        ->and($summary['on_leave'])->toBe(2)
        ->and($summary['holidays'])->toBe(1)
        ->and($summary['minutes'])->toBe(540)
        ->and($summary['absence_rate'])->toBe(20.0);
});

it('ignores holidays scoped to another company', function () {
    $company = CompanyHelper::company();
    $otherCompany = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $calendar = createWeekCalendar($company->id);
    $employee = createOverviewEmployee('Overview Emp S', $calendar, $company->id);

    CalendarLeave::create([
        'name'        => 'Foreign Holiday',
        'date_from'   => '2026-09-08 00:00:00',
        'date_to'     => '2026-09-08 23:59:59',
        'calendar_id' => null,
        'company_id'  => $otherCompany->id,
    ]);

    $service = app(AttendanceOverviewService::class);

    $entries = $service->daily(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')
    )->keyBy('date');

    expect($entries['2026-09-08']['status'])->toBe('absent');
});

it('gives employees without a calendar zero workdays instead of fake absences', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $employee = Employee::factory()->create([
        'name'        => 'Overview Emp NC',
        'user_id'     => User::factory(),
        'time_zone'   => 'UTC',
        'calendar_id' => null,
        'company_id'  => $company->id,
    ]);

    $service = app(AttendanceOverviewService::class);

    $summary = $service->summary(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')
    )->first();

    expect($summary['workdays'])->toBe(0)
        ->and($summary['absent'])->toBe(0);
});

it('measures range length in days with a ninety-three-day limit', function () {
    expect(AttendanceOverviewService::rangeDays('2026-09-01', '2026-12-03'))->toBe(93)
        ->and(AttendanceOverviewService::rangeDays('2026-09-01', '2026-12-04'))->toBe(94);
});

it('registers the overview page with slug, group and shield discovery', function () {
    expect(AttendanceOverview::getSlug())->toBe('attendance/overview')
        ->and(AttendanceOverview::getNavigationGroup())->toBe(NavigationGroup::Attendance)
        ->and(FilamentShield::getPages())->toHaveKey(AttendanceOverview::class);
});

it('renders the overview page for the default current month', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        overviewPageDataset();

        Livewire::test(AttendanceOverview::class)
            ->assertOk()
            ->assertSee('Overview Emp A')
            ->assertSee('Annual Test Leave')
            ->assertSee('National Test Day');
    } finally {
        Carbon::setTestNow();
    }
});

it('denies the overview page without its permission', function () {
    actingAsOverviewUser([]);

    Livewire::test(AttendanceOverview::class)->assertForbidden();
});

it('filters the overview by employee and range', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        overviewPageDataset();

        Livewire::test(AttendanceOverview::class)
            ->fillForm([
                'employee_id' => Employee::where('name', 'Overview Emp A')->firstOrFail()->id,
                'date_from'   => '2026-09-07',
                'date_to'     => '2026-09-13',
            ], 'filterForm')
            ->call('applyFilters')
            ->assertSee('Overview Emp A')
            ->assertSee('Annual Test Leave')
            ->assertDontSee('Sick Test Leave');
    } finally {
        Carbon::setTestNow();
    }
});

it('rejects overview ranges over ninety-three days with a message', function () {
    actingAsOverviewUser();

    Livewire::test(AttendanceOverview::class)
        ->fillForm([
            'employee_id' => null,
            'date_from'   => '2026-01-01',
            'date_to'     => '2026-12-31',
        ], 'filterForm')
        ->call('applyFilters')
        ->assertHasFormErrors(['date_to'], 'filterForm');
});

it('shows the monthly matrix with day markers', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        overviewPageDataset();

        Livewire::test(AttendanceOverview::class)
            ->fillForm([
                'employee_id' => Employee::where('name', 'Overview Emp A')->firstOrFail()->id,
                'date_from'   => '2026-09-07',
                'date_to'     => '2026-09-13',
            ], 'filterForm')
            ->call('applyFilters')
            ->set('activeTab', 'monthly')
            ->assertSee('Mon 7')
            ->assertSee('>A<', false)
            ->assertSee('>L<', false)
            ->assertSee('>H<', false);
    } finally {
        Carbon::setTestNow();
    }
});

it('exposes the expected absence exporter columns', function () {
    $columns = collect(AbsenceReportExporter::getColumns())->map->getName()->all();

    expect($columns)->toBe([
        'name',
        'workdays',
        'present',
        'absent',
        'absence_rate',
    ]);
});

it('maps the week summary to export values matching the manual shape', function () {
    ['empA' => $empA] = overviewServiceDataset();

    $columns = AbsenceReportExporter::getColumns();
    $columnMap = collect($columns)->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])->all();

    $export = new Export(['exporter' => AbsenceReportExporter::class]);
    $exporter = $export->getExporter($columnMap, [
        'date_from' => '2026-09-07',
        'date_to'   => '2026-09-13',
    ]);

    expect($exporter($empA->refresh()))->toBe([
        'Overview Emp A',
        '5',
        '1',
        '1',
        '20%',
    ]);
});
