<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\Attendance\Filament\Exports\AbsenceDetailExporter;
use Webkul\Attendance\Filament\Exports\AbsenceReportExporter;
use Webkul\Attendance\Filament\Pages\AttendanceOverview;
use Webkul\Attendance\Filament\Resources\AttendanceEventResource;
use Webkul\Attendance\Filament\Resources\AttendanceResource;
use Webkul\Attendance\Filament\Tables\AttendanceRegisterTable;
use Webkul\Attendance\Filament\Widgets\AttendanceStatsWidget;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Employee\Filament\Resources\EmployeeResource;
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
        'name'       => 'Week Roster',
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
 * Matches a register cell's exact text (fi-ta-text-item wrapper, with the
 * x-tooltip attribute on its inner span holding the full status word),
 * so legend badges (fi-badge) and column headers never satisfy the assert.
 */
function statusCellRegex(string $word): string
{
    return '/fi-ta-text-item[^>]*>\s*<span[^>]*>\s*'.preg_quote($word, '/').'\s*</';
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
        'time_type'   => 'leave',
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

    expect($summary['workdays'])->toBe(2)
        ->and($summary['present'])->toBe(1)
        ->and($summary['absent'])->toBe(1)
        ->and($summary['on_leave'])->toBe(2)
        ->and($summary['holidays'])->toBe(1)
        ->and($summary['minutes'])->toBe(540)
        ->and($summary['absence_rate'])->toBe(50.0);
});

it('ignores holidays scoped to another company', function () {
    $company = CompanyHelper::company();
    $otherCompany = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $calendar = createWeekCalendar($company->id);
    $employee = createOverviewEmployee('Overview Emp S', $calendar, $company->id);

    CalendarLeave::create([
        'name'        => 'Foreign Holiday',
        'time_type'   => 'leave',
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

    Calendar::query()->forceDelete();

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

it('falls back to a global calendar when the employee has none', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    createWeekCalendar(null);

    $employee = Employee::factory()->create([
        'name'        => 'Overview Emp G',
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

    expect($summary['workdays'])->toBe(5);
});

it('prefers the company calendar over the global one', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    createWeekCalendar(null);

    $companyCalendar = Calendar::factory()->create(['timezone' => 'UTC', 'company_id' => $company->id]);

    foreach ([['morning', 8, 12], ['afternoon', 13, 17]] as [$period, $from, $to]) {
        CalendarAttendance::create([
            'name'         => "monday {$period}",
            'day_of_week'  => 'monday',
            'day_period'   => $period,
            'calendar_id'  => $companyCalendar->id,
            'hour_from'    => $from,
            'hour_to'      => $to,
        ]);
    }

    $employee = Employee::factory()->create([
        'name'        => 'Overview Emp P',
        'user_id'     => User::factory(),
        'time_zone'   => 'UTC',
        'calendar_id' => null,
        'company_id'  => $company->id,
    ]);

    $service = app(AttendanceOverviewService::class);

    $entries = $service->daily(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')
    );

    expect($entries->pluck('date')->all())->toBe(['2026-09-07']);
});

it('measures range length in days with a ninety-three-day limit', function () {
    expect(AttendanceOverviewService::rangeDays('2026-09-01', '2026-12-03'))->toBe(93)
        ->and(AttendanceOverviewService::rangeDays('2026-09-01', '2026-12-04'))->toBe(94);
});

it('registers the overview page with slug, group and shield discovery', function () {
    expect(AttendanceOverview::getSlug())->toBe('attendance/overview')
        ->and(AttendanceOverview::getNavigationGroup())->toBe(NavigationGroup::Attendance)
        ->and(AttendanceOverview::getNavigationLabel())->toBe('Attendance Register')
        ->and(FilamentShield::getPages())->toHaveKey(AttendanceOverview::class);
});

it('locks the attendance navigation order: register, records, raw events, settings', function () {
    expect(AttendanceOverview::getNavigationSort())->toBe(1)
        ->and(AttendanceResource::getNavigationSort())->toBe(2)
        ->and(AttendanceEventResource::getNavigationSort())->toBe(3)
        ->and(Configurations::getNavigationSort())->toBe(4);
});

it('keeps the stats widget compact on phones with a responsive grid', function () {
    $widget = app(AttendanceStatsWidget::class);

    $method = new ReflectionMethod($widget, 'getColumns');
    $method->setAccessible(true);

    expect($method->invoke($widget))->toBe([
        'default' => 2,
        'sm'      => 2,
        'md'      => 4,
        'lg'      => 4,
    ]);
});

it('configures the register table with the shared pagination whitelist', function () {
    actingAsOverviewUser();

    $page = Livewire::test(AttendanceOverview::class)->instance();

    expect($page->getTable()->getPaginationPageOptions())->toBe([10, 25, 50])
        ->and($page->getDefaultTableRecordsPerPageSelectOption())->toBe(25);
});

it('links the register employee name to the employee view page', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        actingAsOverviewUser(['page_attendance_attendance_overview', 'view_employee_employee']);

        ['empA' => $empA] = overviewDataset(Company::whereKey(current_company_id())->firstOrFail());

        $nameColumn = collect(Livewire::test(AttendanceOverview::class)->instance()->getTable()->getColumns())
            ->first(fn (object $column): bool => $column->getName() === 'name');

        expect($nameColumn)->not->toBeNull()
            ->and($nameColumn->record($empA)->getUrl())->toBe(EmployeeResource::getUrl('view', ['record' => $empA]))
            ->and($nameColumn->record($empA)->getTooltip())->toBe($empA->job_title);
    } finally {
        Carbon::setTestNow();
    }
});

it('hides the register employee link without the employee view permission', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        actingAsOverviewUser();

        ['empA' => $empA] = overviewDataset(Company::whereKey(current_company_id())->firstOrFail());

        $nameColumn = collect(Livewire::test(AttendanceOverview::class)->instance()->getTable()->getColumns())
            ->first(fn (object $column): bool => $column->getName() === 'name');

        expect($nameColumn?->record($empA)->getUrl())->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

it('lets users toggle day columns and sort the register by employee name', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        actingAsOverviewUser();

        $table = Livewire::test(AttendanceOverview::class)->instance()->getTable();
        $columns = collect($table->getColumns());

        $dayColumn = $columns->first(fn (object $column): bool => $column->getName() === 'cells.2026-09-01');
        $nameColumn = $columns->first(fn (object $column): bool => $column->getName() === 'name');

        expect($dayColumn->isToggleable())->toBeTrue()
            ->and($dayColumn->isToggledHiddenByDefault())->toBeFalse()
            ->and($nameColumn->isSortable())->toBeTrue()
            ->and($table->getDefaultSortColumn())->toBe('name')
            ->and($table->getDefaultSortDirection())->toBe('asc');
    } finally {
        Carbon::setTestNow();
    }
});

it('shows applied filter chips and clears the employee filter in place', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        actingAsOverviewUser();

        ['empA' => $empA] = overviewDataset(Company::whereKey(current_company_id())->firstOrFail());

        $component = Livewire::test(AttendanceOverview::class)
            ->assertSee('September 2026')
            ->assertDontSee("removeTableFilter('overview', 'employee_id')", false);

        $component
            ->fillForm(['employee_id' => $empA->id], 'filterForm')
            ->call('applyFilters')
            ->assertSee($empA->name)
            ->assertSee("removeTableFilter('overview', 'employee_id')", false)
            ->assertSet('employeeId', $empA->id);

        $component
            ->call('removeTableFilter', 'overview', 'employee_id')
            ->assertSet('employeeId', null)
            ->assertDontSee("removeTableFilter('overview', 'employee_id')", false);

        expect($component->instance()->appliedEmployeeName())->toBeNull()
            ->and($component->instance()->appliedPeriodLabel())->toBe('September 2026');
    } finally {
        Carbon::setTestNow();
    }
});

it('maps every register status to a distinct one-glyph cell code', function () {
    expect(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_PRESENT))->toBe('P')
        ->and(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_ABSENT))->toBe('A')
        ->and(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_ON_LEAVE))->toBe('L')
        ->and(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_HALF_DAY))->toBe('½')
        ->and(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_PENDING))->toBe('?')
        ->and(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_HOLIDAY))->toBe('H')
        ->and(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_WEEKLY_OFF))->toBe('W')
        ->and(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_FUTURE))->toBe('')
        ->and(AttendanceRegisterTable::statusShortLabel(''))->toBe('');

    $codes = collect([
        AttendanceOverviewService::STATUS_PRESENT,
        AttendanceOverviewService::STATUS_ABSENT,
        AttendanceOverviewService::STATUS_ON_LEAVE,
        AttendanceOverviewService::STATUS_HALF_DAY,
        AttendanceOverviewService::STATUS_PENDING,
        AttendanceOverviewService::STATUS_HOLIDAY,
        AttendanceOverviewService::STATUS_WEEKLY_OFF,
    ])->map(fn (string $status): string => AttendanceRegisterTable::statusShortLabel($status));

    expect($codes->unique()->count())->toBe($codes->count());
});

it('renders the executive KPI strip, collapsible daily trend chart, and native table filter popover', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        actingAsOverviewUser();

        overviewPageDataset();

        $component = Livewire::test(AttendanceOverview::class);

        $html = $component->html();

        expect($html)
            ->toContain('attendance-daily-trend')
            ->toContain('fi-ta-filters-dropdown')
            ->toContain('border-collapse: separate')
            ->toContain('fi-reg-name-ltr');
    } finally {
        Carbon::setTestNow();
    }
});

it('narrows register day columns and pins the employee column while scrolling', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        actingAsOverviewUser();

        $component = Livewire::test(AttendanceOverview::class);
        $columns = collect($component->instance()->getTable()->getColumns());

        $dayColumn = $columns->first(fn ($column): bool => $column->getName() === 'cells.2026-09-01');
        $scheduleColumn = $columns->first(fn ($column): bool => $column->getName() === 'schedule');

        expect($dayColumn)->not->toBeNull()
            ->and($dayColumn->getWidth())->toBe('2.75rem')
            ->and($scheduleColumn?->getVisibleFrom())->toBe('md')
            ->and($component->html())->toContain('fi-reg-sticky');
    } finally {
        Carbon::setTestNow();
    }
});

it('renders the overview page for the default current month and restores all day columns after switching from totals', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        overviewPageDataset();

        $component = Livewire::test(AttendanceOverview::class)
            ->assertOk()
            ->assertSet('registerView', 'days')
            ->assertSee('Overview Emp A')
            ->assertSee('Overview Emp B')
            ->assertSee('Tue 1')
            ->assertSee('Wed 30')
            ->assertSee('Work schedule')
            ->assertSee('Status guide')
            ->assertSee('Employee summary')
            ->assertSee('Daily register')
            ->assertDontSee('Upcoming');

        $html = $component->html();

        expect($html)->toMatch(statusCellRegex('P'))
            ->toMatch(statusCellRegex('A'))
            ->toMatch(statusCellRegex('L'))
            ->toMatch(statusCellRegex('½'))
            ->toMatch(statusCellRegex('H'))
            ->toMatch(statusCellRegex('?'))
            ->toMatch(statusCellRegex('W'))
            ->toContain("content: 'Present'")
            ->toContain("content: 'Absent'")
            ->toContain("content: 'On leave'")
            ->toContain("content: 'Half day'")
            ->toContain("content: 'Holiday'")
            ->toContain("content: 'Pending'")
            ->toContain("content: 'Weekly off'")
            ->not->toContain('87.5%');

        $component
            ->call('showRegisterView', 'totals')
            ->assertSee('87.5%');

        expect($component->html())->not->toMatch(statusCellRegex('P'));

        // Switching back from totals to days must restore all 30 visible day columns
        $component
            ->call('showRegisterView', 'days')
            ->assertSet('registerView', 'days')
            ->assertSee('Tue 1')
            ->assertSee('Wed 30');

        expect($component->html())->toMatch(statusCellRegex('P'))
            ->and(count($component->instance()->getTable()->getVisibleColumns()))->toBe(32);
    } finally {
        Carbon::setTestNow();
    }
});

it('ignores an unknown register view and keeps contextual exports', function () {
    actingAsOverviewUser();

    Livewire::test(AttendanceOverview::class)
        ->assertSet('registerView', 'days')
        ->assertActionVisible('exportDetail')
        ->assertActionHidden('export')
        ->call('showRegisterView', 'nope')
        ->assertSet('registerView', 'days')
        ->call('showRegisterView', 'totals')
        ->assertSet('registerView', 'totals')
        ->assertActionVisible('export')
        ->assertActionHidden('exportDetail');
});

it('denies the overview page without its permission', function () {
    actingAsOverviewUser([]);

    Livewire::test(AttendanceOverview::class)->assertForbidden();
});

it('filters the overview by employee and range', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        ['empA' => $empA, 'empB' => $empB] = overviewPageDataset();

        $component = Livewire::test(AttendanceOverview::class)
            ->fillForm([
                'view_mode'   => 'range',
                'employee_id' => $empA->id,
                'date_from'   => '2026-09-07',
                'date_to'     => '2026-09-13',
            ], 'filterForm')
            ->call('applyFilters')
            ->assertCanSeeTableRecords([$empA])
            ->assertCanNotSeeTableRecords([$empB]);

        expect($component->html())->toMatch(statusCellRegex('A'));
    } finally {
        Carbon::setTestNow();
    }
});

it('rejects overview ranges over ninety-three days with a message', function () {
    Carbon::setTestNow('2026-12-31 12:00:00');

    try {
        actingAsOverviewUser();

        Livewire::test(AttendanceOverview::class)
            ->fillForm([
                'employee_id' => null,
                'view_mode'   => 'range',
                'date_from'   => '2026-01-01',
                'date_to'     => '2026-12-31',
            ], 'filterForm')
            ->call('applyFilters')
            ->assertHasFormErrors(['date_to'], 'filterForm');
    } finally {
        Carbon::setTestNow();
    }
});

it('renders colored status words for an applied date range', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        ['empA' => $empA, 'empB' => $empB] = overviewPageDataset();

        $component = Livewire::test(AttendanceOverview::class)
            ->fillForm([
                'view_mode'   => 'range',
                'employee_id' => $empA->id,
                'date_from'   => '2026-09-07',
                'date_to'     => '2026-09-13',
            ], 'filterForm')
            ->call('applyFilters')
            ->assertSee('Mon 7')
            ->assertSee('Sun 13')
            ->assertCanSeeTableRecords([$empA])
            ->assertCanNotSeeTableRecords([$empB]);

        $html = $component->html();

        expect($html)->toMatch(statusCellRegex('P'))
            ->toMatch(statusCellRegex('A'))
            ->toMatch(statusCellRegex('L'))
            ->toMatch(statusCellRegex('½'))
            ->toMatch(statusCellRegex('H'))
            ->toMatch(statusCellRegex('W'));
    } finally {
        Carbon::setTestNow();
    }
});

it('excludes holidays and approved leave from the absence denominator', function () {
    ['empA' => $empA] = overviewServiceDataset();

    $summary = app(AttendanceOverviewService::class)->summary(
        Employee::whereKey($empA->id)->get(),
        Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13')
    )->first();

    expect($summary['workdays'])->toBe(2)
        ->and($summary['present'])->toBe(1)
        ->and($summary['absent'])->toBe(1)
        ->and($summary['on_leave'])->toBe(2)
        ->and($summary['holidays'])->toBe(1)
        ->and($summary['absence_rate'])->toBe(50.0);
});

it('exposes the expected absence exporter columns', function () {
    $columns = collect(AbsenceReportExporter::getColumns())->map->getName()->all();

    expect($columns)->toBe([
        'name',
        'workdays',
        'present',
        'absent',
        'absence_rate',
        'on_leave',
        'holidays',
        'pending',
        'minutes',
    ]);

    $defaults = collect(AbsenceReportExporter::getColumns())
        ->filter(fn ($column): bool => $column->isEnabledByDefault())
        ->map->getName()
        ->all();

    expect($defaults)->toBe([
        'name',
        'workdays',
        'present',
        'absent',
        'absence_rate',
    ]);
});

it('maps the week summary to export values matching the manual shape', function () {
    ['empA' => $empA] = overviewServiceDataset();

    $columnMap = collect(AbsenceReportExporter::getColumns())
        ->filter(fn ($column): bool => $column->isEnabledByDefault())
        ->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])->all();

    $export = new Export(['exporter' => AbsenceReportExporter::class]);
    $exporter = $export->getExporter($columnMap, [
        'date_from' => '2026-09-07',
        'date_to'   => '2026-09-13',
    ]);

    expect($exporter($empA->refresh()))->toBe([
        'Overview Emp A',
        '2',
        '1',
        '1',
        '50%',
    ]);
});

it('exposes the detail exporter columns', function () {
    $columns = collect(AbsenceDetailExporter::getColumns())->map->getName()->all();

    expect($columns)->toBe([
        'employee',
        'schedule',
        'workdays',
        'present',
        'absent',
        'on_leave',
        'hours',
        'absence_rate',
    ]);
});

it('exports one matrix row per employee with summary and daily cells', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        ['empA' => $empA] = overviewPageDataset();

        Livewire::test(AttendanceOverview::class)->callAction('exportDetail', [
            'employee_id' => $empA->id,
            'date_from'   => '2026-09-07',
            'date_to'     => '2026-09-13',
        ]);

        $export = Export::query()->latest('id')->firstOrFail();

        expect($export->exporter)->toBe(AbsenceDetailExporter::class)
            ->and($export->total_rows)->toBe(1)
            ->and($export->successful_rows)->toBe(1);

        $disk = $export->getFileDisk();
        $directory = $export->getFileDirectory();
        $dataFile = collect($disk->files($directory))
            ->first(fn (string $file): bool => str($file)->endsWith('.csv') && ! str($file)->endsWith('headers.csv'));

        expect($dataFile)->not->toBeNull();

        $headers = $disk->get($directory.DIRECTORY_SEPARATOR.'headers.csv');
        $content = $disk->get($dataFile);

        expect(substr_count(trim($content), "\n"))->toBe(0)
            ->and($headers)->toContain('Employee')
            ->and($headers)->toContain('Schedule')
            ->and($headers)->toContain('2026-09-07')
            ->and($content)->toContain('Overview Emp A')
            ->and($content)->toContain('Present')
            ->and($content)->toContain('Absent')
            ->and($content)->toContain('On leave');
    } finally {
        Carbon::setTestNow();
    }
});

it('aborts detail exports above the row cap without writing data rows', function () {
    config()->set('attendance.export.max_detail_rows', 0);

    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        ['empA' => $empA] = overviewPageDataset();

        Livewire::test(AttendanceOverview::class)->callAction('exportDetail', [
            'employee_id' => $empA->id,
            'date_from'   => '2026-09-07',
            'date_to'     => '2026-09-13',
        ]);

        $export = Export::query()->latest('id')->firstOrFail();

        expect($export->exporter)->toBe(AbsenceDetailExporter::class)
            ->and($export->total_rows)->toBe(1)
            ->and($export->successful_rows)->toBe(0);

        $dataFiles = collect($export->getFileDisk()->files($export->getFileDirectory()))
            ->filter(fn (string $file): bool => str($file)->endsWith('.csv') && ! str($file)->endsWith('headers.csv'));

        expect($dataFiles)->toBeEmpty();
    } finally {
        Carbon::setTestNow();
    }
});

it('applies overview filters only through the apply action', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        ['empA' => $empA, 'empB' => $empB] = overviewPageDataset();

        FilamentHelper::actingAs(['page_attendance_attendance_overview'])->forceFill(['is_active' => true])->saveQuietly();

        $component = Livewire::test(AttendanceOverview::class)
            ->assertSee('wire:click="applyTableFilters"', false)
            ->fillForm([
                'view_mode'   => 'range',
                'employee_id' => $empA->id,
                'date_from'   => '2026-09-07',
                'date_to'     => '2026-09-13',
            ], 'filterForm')
            ->assertCanSeeTableRecords([$empA])
            ->assertCanSeeTableRecords([$empB])
            ->call('applyFilters')
            ->assertCanSeeTableRecords([$empA])
            ->assertCanNotSeeTableRecords([$empB]);

        expect($component->html())->toMatch(statusCellRegex('A'));
    } finally {
        Carbon::setTestNow();
    }
});

it('paginates register rows by employee with the shared page sizes', function () {
    actingAsOverviewUser();

    $company = Company::query()->orderBy('id')->firstOrFail();
    $calendar = createWeekCalendar($company->id);

    foreach (range(1, 26) as $index) {
        createOverviewEmployee(sprintf('Overview Emp %02d', $index), $calendar, $company->id);
    }

    $component = Livewire::test(AttendanceOverview::class);

    $employees = Employee::query()
        ->where('name', 'like', 'Overview Emp %')
        ->orderBy('name')
        ->get()
        ->values();

    $first = $employees->first();
    $last = $employees->last();

    $component
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$last]);

    $pageName = $component->instance()->getTablePaginationPageName();

    $component
        ->call('nextPage', $pageName)
        ->assertCanSeeTableRecords([$last])
        ->assertCanNotSeeTableRecords([$first]);
});

it('scopes the export query by employee option and company-less employees', function () {
    ['company' => $company, 'calendar' => $calendar, 'empA' => $empA, 'empB' => $empB] = overviewServiceDataset();

    $companyLess = createOverviewEmployee('Overview Emp NoCo', $calendar, $company->id);
    $companyLess->company_id = null;
    $companyLess->save();

    $scoped = AbsenceReportExporter::scopeQuery(Employee::query(), [
        'date_from'   => '2026-09-07',
        'date_to'     => '2026-09-13',
        'employee_id' => $empA->id,
    ]);

    expect($scoped->pluck('id')->all())->toBe([$empA->id]);

    $all = AbsenceReportExporter::scopeQuery(Employee::query(), [
        'date_from'   => '2026-09-07',
        'date_to'     => '2026-09-13',
        'employee_id' => null,
    ])->pluck('id')->all();

    expect($all)->toContain($empA->id, $empB->id, $companyLess->id);
});

it('ignores resource-bound and non-leave calendar rows when resolving holidays', function () {
    $company = CompanyHelper::company();

    CompanyHelper::actingAsCompanyUser($company);

    $calendar = createWeekCalendar($company->id);
    $employee = createOverviewEmployee('Overview Emp DT', $calendar, $company->id);

    CalendarLeave::create([
        'name'        => 'Planned Downtime',
        'time_type'   => 'other',
        'date_from'   => '2026-09-08 00:00:00',
        'date_to'     => '2026-09-08 23:59:59',
        'calendar_id' => $calendar->id,
        'company_id'  => $company->id,
    ]);

    CalendarLeave::create([
        'name'          => 'Resource Leave',
        'time_type'     => 'leave',
        'date_from'     => '2026-09-09 00:00:00',
        'date_to'       => '2026-09-09 23:59:59',
        'calendar_id'   => $calendar->id,
        'company_id'    => $company->id,
        'resource_type' => 'work_centers',
        'resource_id'   => 424242,
    ]);

    $entries = app(AttendanceOverviewService::class)
        ->daily(Employee::whereKey($employee->id)->get(), Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13'))
        ->keyBy('date');

    expect($entries['2026-09-08']['status'])->toBe('absent')
        ->and($entries['2026-09-09']['status'])->toBe('absent');
});

it('locks applied filter props against client-side updates', function () {
    actingAsOverviewUser();

    expect(fn () => Livewire::test(AttendanceOverview::class)->set('dateFrom', '2020-01-01'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => Livewire::test(AttendanceOverview::class)->set('dateTo', '2030-01-01'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => Livewire::test(AttendanceOverview::class)->set('displayFrom', '2020-01-01'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => Livewire::test(AttendanceOverview::class)->set('displayTo', '2030-01-01'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => Livewire::test(AttendanceOverview::class)->set('rangeMode', 'year'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('resets an invalid per-page selection to the default', function () {
    actingAsOverviewUser();

    Livewire::test(AttendanceOverview::class)
        ->set('tableRecordsPerPage', 0)
        ->assertSet('tableRecordsPerPage', 25);
});

it('falls back to a safe range when applied dates are tampered with', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        actingAsOverviewUser();

        $page = Livewire::test(AttendanceOverview::class)->instance();

        $page->dateFrom = '2030-01-01';
        $page->dateTo = '2020-01-01';
        $page->displayFrom = '2030-01-01';
        $page->displayTo = '2020-01-01';
        $page->getEntries();

        expect($page->displayFrom)->toBe('2026-09-01')
            ->and($page->displayTo)->toBe('2026-09-30')
            ->and($page->dateFrom)->toBe('2026-09-01')
            ->and($page->dateTo)->toBe('2026-09-15');

        $page->displayFrom = '2026-10-01';
        $page->displayTo = '2026-10-31';
        $page->dateFrom = '2026-10-01';
        $page->dateTo = '2026-10-15';
        $page->getEntries();

        expect($page->displayFrom)->toBe('2026-09-01')
            ->and($page->displayTo)->toBe('2026-09-30')
            ->and($page->dateFrom)->toBe('2026-09-01')
            ->and($page->dateTo)->toBe('2026-09-15');
    } finally {
        Carbon::setTestNow();
    }
});

it('prefills the export options from the applied page filters', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        ['empA' => $empA] = overviewPageDataset();

        Livewire::test(AttendanceOverview::class)
            ->fillForm([
                'view_mode'   => 'range',
                'employee_id' => $empA->id,
                'date_from'   => '2026-09-07',
                'date_to'     => '2026-09-13',
            ], 'filterForm')
            ->call('applyFilters')
            ->call('showRegisterView', 'totals')
            ->mountAction('export')
            ->assertActionDataSet([
                'employee_id' => $empA->id,
                'date_from'   => '2026-09-07',
                'date_to'     => '2026-09-13',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});

it('exports the summary over a year-long range', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        ['empA' => $empA] = overviewPageDataset();

        Livewire::test(AttendanceOverview::class)
            ->call('showRegisterView', 'totals')
            ->callAction('export', [
                'employee_id' => $empA->id,
                'date_from'   => '2026-01-01',
                'date_to'     => '2026-09-15',
            ])
            ->assertHasNoActionErrors();

        $export = Export::query()->latest('id')->firstOrFail();

        expect($export->exporter)->toBe(AbsenceReportExporter::class)
            ->and($export->successful_rows)->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

it('rejects summary ranges over a year and detail ranges over ninety-three days', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        overviewPageDataset();

        Livewire::test(AttendanceOverview::class)
            ->call('showRegisterView', 'totals')
            ->callAction('export', [
                'employee_id' => null,
                'date_from'   => '2024-01-01',
                'date_to'     => '2026-09-15',
            ])
            ->assertHasActionErrors(['date_to']);

        Livewire::test(AttendanceOverview::class)
            ->callAction('exportDetail', [
                'employee_id' => null,
                'date_from'   => '2026-01-01',
                'date_to'     => '2026-09-15',
            ])
            ->assertHasActionErrors(['date_to']);
    } finally {
        Carbon::setTestNow();
    }
});

it('registers a full month of cells with weekends, half days and future days', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        ['empA' => $empA] = overviewServiceDataset();

        $rows = app(AttendanceOverviewService::class)->register(
            Employee::whereKey($empA->id)->get(),
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
        );

        expect($rows)->toHaveCount(1);

        $row = $rows->first();

        expect($row['employee_id'])->toBe($empA->id)
            ->and($row['calendar'])->toBe('Week Roster')
            ->and($row['cells'])->toHaveCount(30)
            ->and($row['cells']['2026-09-01'])->toBe('absent')
            ->and($row['cells']['2026-09-05'])->toBe('weekly_off')
            ->and($row['cells']['2026-09-07'])->toBe('present')
            ->and($row['cells']['2026-09-09'])->toBe('on_leave')
            ->and($row['cells']['2026-09-10'])->toBe('holiday')
            ->and($row['cells']['2026-09-11'])->toBe('half_day')
            ->and($row['cells']['2026-09-13'])->toBe('weekly_off')
            ->and($row['cells']['2026-09-20'])->toBe('future')
            ->and($row['totals']['present'])->toBe(1)
            ->and($row['totals']['absent'])->toBe(7)
            ->and($row['totals']['workdays'])->toBe(8)
            ->and($row['totals']['absence_rate'])->toBe(87.5);
    } finally {
        Carbon::setTestNow();
    }
});

it('spans every calendar day of the month with a word in each cell', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        ['empA' => $empA, 'empB' => $empB] = overviewPageDataset();

        $page = Livewire::test(AttendanceOverview::class)->instance();

        $days = $page->getGridDays();

        expect($days)->toHaveCount(30)
            ->and($days[0])->toBe('2026-09-01')
            ->and($days[29])->toBe('2026-09-30');

        $grid = collect($page->getGrid())->keyBy('employee_id');

        expect($grid)->toHaveCount(2)
            ->and($grid[$empA->id]['calendar'])->toBe('Week Roster')
            ->and($grid[$empA->id]['cells']['2026-09-07'])->toBe('present')
            ->and($grid[$empA->id]['cells']['2026-09-08'])->toBe('absent')
            ->and($grid[$empA->id]['cells']['2026-09-11'])->toBe('half_day')
            ->and($grid[$empB->id]['cells']['2026-09-08'])->toBe('pending')
            ->and($grid[$empA->id]['totals']['absent'])->toBe(7)
            ->and($grid[$empA->id]['totals']['absence_rate'])->toBe(87.5);
    } finally {
        Carbon::setTestNow();
    }
});

it('switches between month and range views only through apply', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        ['empA' => $empA] = overviewPageDataset();

        $component = Livewire::test(AttendanceOverview::class)
            ->assertSet('displayFrom', '2026-09-01')
            ->assertSet('displayTo', '2026-09-30')
            ->assertSet('dateFrom', '2026-09-01')
            ->assertSet('dateTo', '2026-09-15')
            ->fillForm([
                'view_mode'   => 'range',
                'employee_id' => $empA->id,
                'date_from'   => '2026-09-07',
                'date_to'     => '2026-09-13',
            ], 'filterForm')
            ->call('applyFilters')
            ->assertSet('displayFrom', '2026-09-07')
            ->assertSet('displayTo', '2026-09-13')
            ->assertSet('dateFrom', '2026-09-07')
            ->assertSet('dateTo', '2026-09-13');

        expect($component->instance()->getGridDays())->toHaveCount(7);

        $component
            ->fillForm([
                'view_mode' => 'month',
                'month'     => 9,
                'year'      => 2026,
            ], 'filterForm')
            ->call('applyFilters')
            ->assertSet('displayFrom', '2026-09-01')
            ->assertSet('displayTo', '2026-09-30')
            ->assertSet('dateFrom', '2026-09-01')
            ->assertSet('dateTo', '2026-09-15');

        expect($component->instance()->getGridDays())->toHaveCount(30);
    } finally {
        Carbon::setTestNow();
    }
});

it('rejects a future month with a message', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        actingAsOverviewUser();

        Livewire::test(AttendanceOverview::class)
            ->fillForm([
                'view_mode' => 'month',
                'month'     => 12,
                'year'      => 2026,
            ], 'filterForm')
            ->call('applyFilters')
            ->assertHasFormErrors(['month'], 'filterForm');
    } finally {
        Carbon::setTestNow();
    }
});

it('summarizes the month with totals, legend and daily trend', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        overviewPageDataset();

        $page = Livewire::test(AttendanceOverview::class)->instance();

        $totals = collect($page->getSummaryTotals())->keyBy('label');

        expect($totals)->toHaveCount(7)
            ->and($totals['Employees']['value'])->toBe(2)
            ->and($totals['Present days']['value'])->toBe(1)
            ->and($totals['Absent days']['value'])->toBe(17)
            ->and($totals['Leave days']['value'])->toBe(2)
            ->and($totals['Pending']['value'])->toBe(1)
            ->and($totals['Holidays']['value'])->toBe(2)
            ->and($totals['Absence rate']['value'])->toBe(94.4);

        $legend = collect($page->getLegend());

        expect($legend->pluck('key')->all())->toBe([
            'present',
            'absent',
            'on_leave',
            'half_day',
            'pending',
            'holiday',
            'weekly_off',
            'future',
        ])
            ->and($legend->firstWhere('key', 'present')['label'])->toBe('Present')
            ->and($legend->firstWhere('key', 'present')['color'])->toBe('success')
            ->and($legend->firstWhere('key', 'half_day')['color'])->toBe('info')
            ->and($legend->firstWhere('key', 'pending')['color'])->toBe('warning')
            ->and($legend->firstWhere('key', 'holiday')['color'])->toBe('primary')
            ->and($legend->firstWhere('key', 'weekly_off')['color'])->toBe('gray');

        $trend = collect($page->getDailyTrend());

        expect($trend)->toHaveCount(30)
            ->and($trend->firstWhere('date', '2026-09-07')['present'])->toBe(1)
            ->and($trend->firstWhere('date', '2026-09-07')['absent'])->toBe(1)
            ->and($trend->firstWhere('date', '2026-09-07')['leave'])->toBe(0)
            ->and($trend->firstWhere('date', '2026-09-07')['off'])->toBe(0)
            ->and($trend->firstWhere('date', '2026-09-07')['total'])->toBe(2)
            ->and($trend->firstWhere('date', '2026-09-08')['absent'])->toBe(2)
            ->and($trend->firstWhere('date', '2026-09-09')['leave'])->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

it('maps register statuses to localized labels and colors', function () {
    expect(AttendanceRegisterTable::statusLabel('present'))->toBe('Present')
        ->and(AttendanceRegisterTable::statusLabel('half_day'))->toBe('Half day')
        ->and(AttendanceRegisterTable::statusLabel('weekly_off'))->toBe('Weekly off')
        ->and(AttendanceRegisterTable::statusLabel('future'))->toBe('')
        ->and(AttendanceRegisterTable::statusColor('present'))->toBe('success')
        ->and(AttendanceRegisterTable::statusColor('absent'))->toBe('danger')
        ->and(AttendanceRegisterTable::statusColor('on_leave'))->toBe('info')
        ->and(AttendanceRegisterTable::statusColor('half_day'))->toBe('info')
        ->and(AttendanceRegisterTable::statusColor('pending'))->toBe('warning')
        ->and(AttendanceRegisterTable::statusColor('holiday'))->toBe('primary')
        ->and(AttendanceRegisterTable::statusColor('weekly_off'))->toBe('gray')
        ->and(AttendanceRegisterTable::statusColor('future'))->toBeNull();
});

it('formats day cell columns from the raw display status', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        overviewPageDataset();

        $page = Livewire::test(AttendanceOverview::class)->instance();

        $column = collect($page->getTable()->getColumns())
            ->first(fn (object $column): bool => $column->getName() === 'cells.2026-09-07');

        expect($column)->not->toBeNull()
            ->and($column->formatState('present'))->toBe('P')
            ->and($column->formatState('weekly_off'))->toBe('W')
            ->and($column->formatState('future'))->toBe('')
            ->and($column->getTooltip('present'))->toBe('Present')
            ->and($column->getTooltip('future'))->toBeNull()
            ->and($column->getColor('present'))->toBe('success')
            ->and($column->getColor('absent'))->toBe('danger');
    } finally {
        Carbon::setTestNow();
    }
});

it('aggregates a year into monthly cells and a yearly summary', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        ['empA' => $empA] = overviewPageDataset();

        $component = Livewire::test(AttendanceOverview::class)
            ->call('showRegisterView', 'totals')
            ->fillForm([
                'view_mode' => 'year',
                'year'      => 2026,
            ], 'filterForm')
            ->call('applyFilters')
            ->assertSet('rangeMode', 'year')
            ->assertSet('displayFrom', '2026-01-01')
            ->assertSet('displayTo', '2026-12-31')
            ->assertSet('dateFrom', '2026-01-01')
            ->assertSet('dateTo', '2026-09-15')
            ->assertHasNoFormErrors();

        $instance = $component->instance();

        expect($instance->getGridDays())->toHaveCount(365)
            ->and($instance->getGridDays()[0])->toBe('2026-01-01')
            ->and($instance->getGridDays()[364])->toBe('2026-12-31');

        $grid = collect($instance->getGrid())->keyBy('employee_id');

        expect($grid[$empA->id]['cells'])->toHaveCount(9)
            ->and($grid[$empA->id]['cells']['2026-09-01']['present'])->toBe(1)
            ->and($grid[$empA->id]['totals']['present'])->toBe(1)
            ->and($grid[$empA->id]['totals']['on_leave'])->toBe(2)
            ->and($grid[$empA->id]['totals']['holidays'])->toBe(1)
            ->and($grid[$empA->id]['totals']['workdays'])->toBeGreaterThan(100);
    } finally {
        Carbon::setTestNow();
    }
});

it('uses year mode for per-employee totals and resets to month mode when switching back to daily register', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        overviewPageDataset();

        Livewire::test(AttendanceOverview::class)
            ->call('showRegisterView', 'totals')
            ->fillForm([
                'view_mode' => 'year',
                'year'      => 2026,
            ], 'filterForm')
            ->call('applyFilters')
            ->assertSet('registerView', 'totals')
            ->assertSet('rangeMode', 'year')
            ->assertSee('Overview Emp A')
            ->assertSee('Present days')
            ->assertDontSee('Wed 30')
            ->call('showRegisterView', 'days')
            ->assertSet('registerView', 'days')
            ->assertSet('rangeMode', 'month')
            ->assertSee('Wed 30');
    } finally {
        Carbon::setTestNow();
    }
});

it('locks each totals value to its own column in order', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        overviewPageDataset();

        $page = Livewire::test(AttendanceOverview::class)
            ->call('showRegisterView', 'totals')
            ->assertSet('registerView', 'totals')
            ->instance();

        $columns = collect($page->getTable()->getColumns());
        $names = $columns->map->getName()->values()->all();

        expect($names)->toBe([
            'name',
            'totals.present',
            'totals.absent',
            'totals.on_leave',
            'totals.absence_rate',
            'totals.pending',
            'totals.holidays',
            'totals.workdays',
            'schedule',
        ])
            ->and($columns->firstWhere(fn ($column): bool => $column->getName() === 'name')->getWidth())->toBe('180px')
            ->and($columns->firstWhere(fn ($column): bool => $column->getName() === 'totals.present')->getWidth())->toBe('110px');
    } finally {
        Carbon::setTestNow();
    }
});

it('restores the register tab from the URL and rejects tampered values', function () {
    actingAsOverviewUser();
    overviewPageDataset();

    Livewire::withQueryParams(['registerView' => 'totals'])
        ->test(AttendanceOverview::class)
        ->assertSet('registerView', 'totals');

    Livewire::withQueryParams(['registerView' => 'nope'])
        ->test(AttendanceOverview::class)
        ->assertSet('registerView', 'days');
});

it('offers the year range only on the summary tab', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        actingAsOverviewUser();
        overviewPageDataset();

        $component = Livewire::test(AttendanceOverview::class)
            ->assertSet('registerView', 'days');

        $daysOptions = $component->instance()->getSchema('filterForm')->getComponent('view_mode')->getOptions();

        expect(array_keys($daysOptions))->toBe(['month', 'range']);

        $component->call('showRegisterView', 'totals');

        $totalsOptions = $component->instance()->getSchema('filterForm')->getComponent('view_mode')->getOptions();

        expect(array_keys($totalsOptions))->toBe(['month', 'range', 'year']);
    } finally {
        Carbon::setTestNow();
    }
});

it('names export files by kind, period and scope', function () {
    ['empA' => $empA] = overviewServiceDataset();

    $columnMap = collect(AbsenceReportExporter::getColumns())
        ->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])->all();

    $summaryAll = (new Export(['exporter' => AbsenceReportExporter::class]))
        ->getExporter($columnMap, ['date_from' => '2026-09-01', 'date_to' => '2026-09-15']);

    expect($summaryAll->getFileName(new Export))->toBe('absence-summary_2026-09-01_to_2026-09-15_all');

    $summaryScoped = (new Export(['exporter' => AbsenceReportExporter::class]))
        ->getExporter($columnMap, [
            'employee_id' => $empA->id,
            'date_from'   => '2026-09-01',
            'date_to'     => '2026-09-15',
        ]);

    expect($summaryScoped->getFileName(new Export))->toBe('absence-summary_2026-09-01_to_2026-09-15_emp-overview-emp-a');

    $detailAll = (new Export(['exporter' => AbsenceDetailExporter::class]))
        ->getExporter($columnMap, ['date_from' => '2026-09-01', 'date_to' => '2026-09-15']);

    expect($detailAll->getFileName(new Export))->toBe('absence-detail_2026-09-01_to_2026-09-15_all');
});

it('falls back to the employee id when the name has no slug form', function () {
    $employee = Employee::factory()->create(['name' => '***']);

    $columnMap = collect(AbsenceReportExporter::getColumns())
        ->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])->all();

    $exporter = (new Export(['exporter' => AbsenceReportExporter::class]))
        ->getExporter($columnMap, [
            'employee_id' => $employee->id,
            'date_from'   => '2026-09-01',
            'date_to'     => '2026-09-15',
        ]);

    expect($exporter->getFileName(new Export))->toBe("absence-summary_2026-09-01_to_2026-09-15_emp-{$employee->id}");
});

it('assigns script-direction classes to sticky employee cells so Arabic and English names truncate from the end', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        actingAsOverviewUser();

        $company = Company::whereKey(current_company_id())->firstOrFail();
        $calendar = createWeekCalendar($company->id);

        createOverviewEmployee('عبدالله الحسين الحرازي', $calendar, $company->id);
        createOverviewEmployee('Abdullah Al-Hussein Al-Harazi', $calendar, $company->id);

        $html = Livewire::test(AttendanceOverview::class)->html();

        expect($html)
            ->toContain('fi-reg-name-rtl')
            ->toContain('fi-reg-name-ltr');
    } finally {
        Carbon::setTestNow();
    }
});

it('scopes register employees by barcode, registered attendance writer plugin, or last-year activity from AttendanceSettings', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        actingAsOverviewUser();

        $company = Company::whereKey(current_company_id())->firstOrFail();
        $calendar = createWeekCalendar($company->id);

        $empWithBarcode = createOverviewEmployee('Emp With Barcode', $calendar, $company->id);
        $empWithBarcode->forceFill(['barcode' => 'BIO-1001'])->saveQuietly();

        $empWithRecentActivity = createOverviewEmployee('Emp Recent Activity', $calendar, $company->id);
        $empWithRecentActivity->forceFill(['barcode' => null])->saveQuietly();
        Attendance::create([
            'employee_id' => $empWithRecentActivity->id,
            'work_date'   => '2026-05-10',
            'check_in'    => '2026-05-10 08:00:00',
            'check_out'   => '2026-05-10 17:00:00',
            'source'      => 'manual',
            'company_id'  => $company->id,
        ]);

        $empRemotePlugin = createOverviewEmployee('Emp Remote Plugin', $calendar, $company->id);
        $empRemotePlugin->forceFill(['barcode' => null, 'job_title' => 'remote-enrolled'])->saveQuietly();

        $empUnenrolled = createOverviewEmployee('Emp Unenrolled', $calendar, $company->id);
        $empUnenrolled->forceFill(['barcode' => null])->saveQuietly();
        Attendance::create([
            'employee_id' => $empUnenrolled->id,
            'work_date'   => '2024-01-10',
            'check_in'    => '2024-01-10 08:00:00',
            'check_out'   => '2024-01-10 17:00:00',
            'source'      => 'manual',
            'company_id'  => $company->id,
        ]);

        WriterRegistry::register(
            'remote-attendance',
            fn (): string => 'Remote Attendance',
            fn ($query)   => $query->where('job_title', 'remote-enrolled'),
        );

        $enrolledIds = AttendanceOverviewService::scopeEligibleEmployees(
            Employee::query(),
            AttendanceSettings::SCOPE_ENROLLED,
        )->pluck('id')->all();

        expect($enrolledIds)
            ->toContain($empWithBarcode->id, $empRemotePlugin->id)
            ->not->toContain($empWithRecentActivity->id, $empUnenrolled->id);

        $recentIds = AttendanceOverviewService::scopeEligibleEmployees(
            Employee::query(),
            AttendanceSettings::SCOPE_RECENT_ACTIVITY,
        )->pluck('id')->all();

        expect($recentIds)
            ->toContain($empWithRecentActivity->id)
            ->not->toContain($empWithBarcode->id, $empRemotePlugin->id, $empUnenrolled->id);

        $combinedIds = AttendanceOverviewService::scopeEligibleEmployees(
            Employee::query(),
            AttendanceSettings::SCOPE_ENROLLED_OR_ACTIVE,
        )->pluck('id')->all();

        expect($combinedIds)
            ->toContain($empWithBarcode->id, $empRemotePlugin->id, $empWithRecentActivity->id)
            ->not->toContain($empUnenrolled->id);
    } finally {
        WriterRegistry::flush();
        Carbon::setTestNow();
    }
});
