<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use Filament\Actions\Exports\Models\Export;
use Filament\Support\Contracts\HasIcon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Webkul\Attendance\Events\EventGateway;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\EditCalendar;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\RelationManagers\EmployeesRelationManager;
use Webkul\Attendance\Filament\Clusters\Settings\Pages\ManageAttendance as ManageAttendanceSettings;
use Webkul\Attendance\Filament\Exports\AbsenceReportExporter;
use Webkul\Attendance\Filament\Exports\AttendanceExporter;
use Webkul\Attendance\Filament\Pages\AttendanceOverview;
use Webkul\Attendance\Filament\Pages\ManageAttendance;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Pages\CreateAttendance;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Pages\EditAttendance;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use Webkul\Attendance\Filament\Widgets\AttendanceStatsWidget;
use Webkul\Attendance\Filament\Widgets\TodayAttendanceWidget;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Policies\AttendancePolicy;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Security\Bouncer;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Traits\TimeOffHelper;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');

    WriterRegistry::flush();
    WriterRegistry::register('biometric-attendance');
});

afterEach(function () {
    $settings = app(AttendanceSettings::class);

    $settings->employee_scope = AttendanceSettings::SCOPE_ALL;
    $settings->count_pending_leave_as_absent = true;
    $settings->duplicate_punch_window_seconds = AttendanceSettings::DEFAULT_DUPLICATE_PUNCH_SECONDS;
    $settings->max_shift_hours = AttendanceSettings::DEFAULT_MAX_SHIFT_HOURS;

    $settings->save();
});

function hardeningCompanyUser(Company $company, array $permissions, bool $global = true): User
{
    $user = FilamentHelper::actingAsCompanyUser($company, $permissions, $global);

    $user->forceFill(['is_active' => true])->saveQuietly();

    return $user;
}

function hardeningResetBouncer(): void
{
    (new ReflectionProperty(Bouncer::class, 'authorizedUserIdsCache'))->setValue(null, null);
}

it('stores shift hours as decimal and exposes clock values', function () {
    $calendar = Calendar::factory()->create(['timezone' => 'UTC']);

    $row = CalendarAttendance::create([
        'name'        => 'Day shift',
        'day_of_week' => 'monday',
        'day_period'  => 'morning',
        'calendar_id' => $calendar->id,
        'hour_from'   => '08:30',
        'hour_to'     => '17:15',
    ]);

    expect($row->getRawOriginal('hour_from'))->toBe('8.5')
        ->and($row->getRawOriginal('hour_to'))->toBe('17.25')
        ->and($row->hour_from)->toBe('08:30')
        ->and($row->hour_to)->toBe('17:15');

    $legacy = CalendarAttendance::create([
        'name'        => 'Legacy shift',
        'day_of_week' => 'tuesday',
        'day_period'  => 'morning',
        'calendar_id' => $calendar->id,
        'hour_from'   => 8,
        'hour_to'     => 17,
    ]);

    expect($legacy->getRawOriginal('hour_from'))->toBe('8')
        ->and($legacy->hour_from)->toBe('08:00')
        ->and(CalendarAttendance::minutesOfDay('08:30:00'))->toBe(510.0)
        ->and(CalendarAttendance::minutesOfDay('8.5'))->toBe(510.0)
        ->and(CalendarAttendance::minutesOfDay(8))->toBe(480.0);
});

it('renders the overview when a legacy clock-string shift row exists', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, ['page_attendance_attendance_overview']);

    $calendar = Calendar::factory()->create(['company_id' => $company->id, 'timezone' => 'UTC']);

    $row = CalendarAttendance::create([
        'name'        => 'Legacy clock shift',
        'day_of_week' => 'monday',
        'day_period'  => 'morning',
        'calendar_id' => $calendar->id,
        'hour_from'   => 8,
        'hour_to'     => 17,
    ]);

    DB::table('calendar_attendances')
        ->where('id', $row->id)
        ->update(['hour_from' => '08:30:00', 'hour_to' => '17:15:00']);

    $employee = Employee::factory()->create([
        'company_id'  => $company->id,
        'calendar_id' => $calendar->id,
        'time_zone'   => 'UTC',
    ]);

    Livewire::test(AttendanceOverview::class)->assertOk();

    $entries = app(AttendanceOverviewService::class)->daily(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-09-07'),
        Carbon::parse('2026-09-07'),
    );

    expect($entries)->not->toBeEmpty();
});

it('filters the register and exports by department and work schedule', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, ['page_attendance_attendance_overview']);

    $department = Department::factory()->create(['company_id' => $company->id]);
    $calendar = Calendar::factory()->create(['company_id' => $company->id]);
    $matching = Employee::factory()->create([
        'company_id'    => $company->id,
        'department_id' => $department->id,
        'calendar_id'   => $calendar->id,
        'user_id'       => User::factory(),
    ]);
    $other = Employee::factory()->create([
        'company_id' => $company->id,
        'user_id'    => User::factory(),
    ]);

    Livewire::test(AttendanceOverview::class)
        ->fillForm([
            'department_id' => $department->id,
            'calendar_id'   => $calendar->id,
        ], 'filterForm')
        ->call('applyFilters')
        ->assertSet('departmentId', $department->id)
        ->assertSet('calendarId', $calendar->id);

    $scoped = AbsenceReportExporter::scopeQuery(Employee::query(), [
        'department_id' => $department->id,
        'calendar_id'   => $calendar->id,
    ]);

    expect($scoped->pluck('id')->all())->toBe([$matching->id]);

    $manual = Attendance::create([
        'employee_id' => $matching->id,
        'work_date'   => '2026-09-01',
        'check_in'    => '2026-09-01 08:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
    ]);

    Attendance::create([
        'employee_id' => $other->id,
        'work_date'   => '2026-09-01',
        'check_in'    => '2026-09-01 08:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
    ]);

    $attendanceScoped = AttendanceExporter::scopeQuery(Attendance::query(), [
        'department_id' => $department->id,
        'calendar_id'   => $calendar->id,
    ]);

    expect($attendanceScoped->pluck('id')->all())->toBe([$manual->id]);
});

it('keeps Arabic letters in export filenames', function () {
    $employee = Employee::factory()->create(['name' => 'محمد علي']);

    $columnMap = collect(AbsenceReportExporter::getColumns())
        ->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])->all();

    $exporter = (new Export(['exporter' => AbsenceReportExporter::class]))
        ->getExporter($columnMap, [
            'employee_id' => $employee->id,
            'date_from'   => '2026-09-01',
            'date_to'     => '2026-09-15',
        ]);

    expect($exporter->getFileName(new Export))
        ->toBe('absence-summary_2026-09-01_to_2026-09-15_emp-محمد-علي');
});

it('persists the new governance settings and enforces the shift limit', function () {
    hardeningCompanyUser(CompanyHelper::company(), ['page_attendance_manage_attendance']);

    Livewire::test(ManageAttendance::class)
        ->assertOk()
        ->assertSee(__('attendance::filament/pages/manage-attendance.sections.governance.title'))
        ->fillForm([
            'count_pending_leave_as_absent'  => false,
            'duplicate_punch_window_seconds' => 90,
            'max_shift_hours'                => 12,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AttendanceSettings::countPendingLeaveAsAbsent())->toBeFalse()
        ->and(AttendanceSettings::duplicatePunchWindowSeconds())->toBe(90)
        ->and(AttendanceSettings::maxShiftHours())->toBe(12);

    $checkIn = Carbon::parse('2026-09-01 08:00:00');

    expect(Attendance::isValidCheckout($checkIn, Carbon::parse('2026-09-01 21:00:00')))->toBeFalse()
        ->and(Attendance::isValidCheckout($checkIn, Carbon::parse('2026-09-01 19:00:00')))->toBeTrue();
});

it('assigns employees to a shift by selection or department', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, [
        'view_any_support_calendar',
        'view_support_calendar',
        'update_support_calendar',
        'update_employees_employee',
    ]);

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);
    $department = Department::factory()->create(['company_id' => $company->id]);
    $selected = Employee::factory()->create([
        'company_id' => $company->id,
        'user_id'    => User::factory(),
    ]);
    $departmentEmployee = Employee::factory()->create([
        'company_id'    => $company->id,
        'department_id' => $department->id,
        'user_id'       => User::factory(),
    ]);

    Livewire::test(EditCalendar::class, ['record' => $calendar->getRouteKey()])
        ->assertActionVisible('assignEmployees')
        ->callAction('assignEmployees', ['employee_ids' => [$selected->id]])
        ->assertHasNoActionErrors();

    expect($selected->refresh()->calendar_id)->toBe($calendar->id)
        ->and($departmentEmployee->refresh()->calendar_id)->toBeNull();

    Livewire::test(EditCalendar::class, ['record' => $calendar->getRouteKey()])
        ->callAction('assignEmployees', ['department_id' => $department->id])
        ->assertHasNoActionErrors();

    expect($departmentEmployee->refresh()->calendar_id)->toBe($calendar->id);
});

it('bulk deletes manual rows only and skips device rows', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, [
        'view_any_attendance_attendance',
        'delete_any_attendance_attendance',
        'delete_attendance_attendance',
    ]);

    $employee = Employee::factory()->create(['company_id' => $company->id]);

    $manual = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-01',
        'check_in'    => '2026-09-01 08:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
    ]);

    $device = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-02',
        'check_in'    => '2026-09-02 08:00:00',
        'source'      => 'biometric-attendance',
        'company_id'  => $company->id,
    ]);

    Livewire::test(ListAttendances::class)
        ->callTableBulkAction('delete', [$manual, $device]);

    expect(Attendance::query()->whereKey($manual->id)->exists())->toBeFalse()
        ->and(Attendance::query()->whereKey($device->id)->exists())->toBeTrue();
});

it('scopes attendance view by the individual profile and lets global users see everything', function () {
    $company = CompanyHelper::company();

    $individual = CompanyHelper::actingAsCompanyUser($company, ['view_attendance_attendance']);
    $individual->forceFill(['resource_permission' => PermissionType::INDIVIDUAL])->saveQuietly();

    $otherUser = User::factory()->create();

    $own = Attendance::create([
        'employee_id' => Employee::factory()->create(['company_id' => $company->id, 'user_id' => User::factory()])->id,
        'work_date'   => '2026-09-01',
        'check_in'    => '2026-09-01 08:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
        'creator_id'  => $individual->id,
    ]);

    $foreign = Attendance::create([
        'employee_id' => Employee::factory()->create(['company_id' => $company->id, 'user_id' => User::factory()])->id,
        'work_date'   => '2026-09-02',
        'check_in'    => '2026-09-02 08:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
        'creator_id'  => $otherUser->id,
    ]);

    hardeningResetBouncer();

    expect($individual->can('view', $own))->toBeTrue()
        ->and($individual->can('view', $foreign))->toBeFalse();

    $global = FilamentHelper::actingAs(['view_attendance_attendance']);

    hardeningResetBouncer();

    expect($global->can('view', $foreign))->toBeTrue();
});

it('assigns employees with the real update permission and localized copy', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, [
        'view_any_support_calendar',
        'view_support_calendar',
        'update_support_calendar',
        'update_employee_employee',
    ]);

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);
    $department = Department::factory()->create(['company_id' => $company->id]);
    $job = EmployeeJobPosition::factory()->create([
        'company_id'    => $company->id,
        'department_id' => $department->id,
    ]);

    $matching = Employee::factory()->create([
        'company_id'    => $company->id,
        'department_id' => $department->id,
        'job_id'        => $job->id,
        'user_id'       => User::factory(),
    ]);

    $otherJob = Employee::factory()->create([
        'company_id'    => $company->id,
        'department_id' => $department->id,
        'user_id'       => User::factory(),
    ]);

    Livewire::test(EditCalendar::class, ['record' => $calendar->getRouteKey()])
        ->assertActionVisible('assignEmployees')
        ->callAction('assignEmployees', [
            'department_id' => $department->id,
            'job_id'        => $job->id,
        ])
        ->assertHasNoActionErrors();

    expect($matching->refresh()->calendar_id)->toBe($calendar->id)
        ->and($otherJob->refresh()->calendar_id)->toBeNull()
        ->and(__('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.label'))
        ->not->toContain('assign-employees');
});

it('includes employees inheriting the company default shift in the calendar filter', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, ['page_attendance_attendance_overview']);

    $default = Calendar::factory()->create(['company_id' => $company->id, 'is_active' => true]);
    CalendarAttendance::create([
        'name'        => 'Default mon',
        'day_of_week' => 'monday',
        'day_period'  => 'morning',
        'calendar_id' => $default->id,
        'hour_from'   => 8,
        'hour_to'     => 17,
    ]);

    $other = Calendar::factory()->create(['company_id' => $company->id, 'is_active' => true]);
    CalendarAttendance::create([
        'name'        => 'Other mon',
        'day_of_week' => 'monday',
        'day_period'  => 'morning',
        'calendar_id' => $other->id,
        'hour_from'   => 8,
        'hour_to'     => 17,
    ]);

    $inherited = Employee::factory()->create([
        'company_id'  => $company->id,
        'calendar_id' => null,
        'user_id'     => User::factory(),
    ]);

    $assignedOther = Employee::factory()->create([
        'company_id'  => $company->id,
        'calendar_id' => $other->id,
        'user_id'     => User::factory(),
    ]);

    $scopedIds = AttendanceOverviewService::scopeByCalendar(Employee::query(), $default->id)
        ->pluck('id')
        ->all();

    expect($scopedIds)->toBe([$inherited->id])
        ->and($scopedIds)->not->toContain($assignedOther->id);
});

it('keeps pending leave in the leave trend when the setting is off and clears all table filters', function () {
    TestBootstrapHelper::ensurePluginInstalled('time-off');

    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        $company = CompanyHelper::company();

        hardeningCompanyUser($company, ['page_attendance_attendance_overview']);

        $department = Department::factory()->create(['company_id' => $company->id]);

        $calendar = Calendar::factory()->create([
            'company_id' => $company->id,
            'timezone'   => 'UTC',
        ]);

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
            CalendarAttendance::create([
                'name'        => $day,
                'day_of_week' => $day,
                'day_period'  => 'morning',
                'calendar_id' => $calendar->id,
                'hour_from'   => 8,
                'hour_to'     => 17,
            ]);
        }

        $employee = Employee::factory()->create([
            'company_id'    => $company->id,
            'department_id' => $department->id,
            'calendar_id'   => $calendar->id,
            'time_zone'     => 'UTC',
            'user_id'       => User::factory(),
        ]);

        $type = LeaveType::create([
            'name'                  => 'Pending Test Leave',
            'leave_validation_type' => 'both',
            'company_id'            => $company->id,
        ]);

        Leave::create([
            'employee_id'       => $employee->id,
            'holiday_status_id' => $type->id,
            'date_from'         => '2026-09-08 00:00:00',
            'date_to'           => '2026-09-08 00:00:00',
            'request_date_from' => '2026-09-08',
            'request_date_to'   => '2026-09-08',
            'state'             => State::CONFIRM,
            'company_id'        => $company->id,
        ]);

        $settings = app(AttendanceSettings::class);
        $settings->count_pending_leave_as_absent = false;
        $settings->save();

        $component = Livewire::test(AttendanceOverview::class)
            ->fillForm([
                'employee_id'   => $employee->id,
                'department_id' => $department->id,
                'calendar_id'   => $calendar->id,
            ], 'filterForm')
            ->call('applyFilters')
            ->assertSet('employeeId', $employee->id)
            ->assertSet('departmentId', $department->id)
            ->assertSet('calendarId', $calendar->id);

        $trend = collect($component->instance()->getDailyTrend())->keyBy('date');

        expect($trend['2026-09-08']['absent'])->toBe(0)
            ->and($trend['2026-09-08']['leave'])->toBe(1);

        $component
            ->call('removeTableFilters')
            ->assertSet('employeeId', null)
            ->assertSet('departmentId', null)
            ->assertSet('calendarId', null);
    } finally {
        Carbon::setTestNow();
    }
});

it('denies individual users update and delete access to foreign attendance rows', function () {
    $company = CompanyHelper::company();

    $individual = hardeningCompanyUser($company, [
        'view_attendance_attendance',
        'update_attendance_attendance',
        'delete_attendance_attendance',
    ]);

    $individual->forceFill(['resource_permission' => PermissionType::INDIVIDUAL])->saveQuietly();

    $otherUser = User::factory()->create();
    $today = Carbon::today()->toDateString();

    $own = Attendance::create([
        'employee_id' => Employee::factory()->create(['company_id' => $company->id, 'user_id' => User::factory()])->id,
        'work_date'   => $today,
        'check_in'    => $today.' 08:00:00',
        'check_out'   => $today.' 17:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
        'creator_id'  => $individual->id,
    ]);

    $foreign = Attendance::create([
        'employee_id' => Employee::factory()->create(['company_id' => $company->id, 'user_id' => User::factory()])->id,
        'work_date'   => $today,
        'check_in'    => $today.' 08:00:00',
        'check_out'   => $today.' 17:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
        'creator_id'  => $otherUser->id,
    ]);

    hardeningResetBouncer();

    expect($individual->can('update', $own))->toBeTrue()
        ->and($individual->can('delete', $own))->toBeTrue()
        ->and($individual->can('update', $foreign))->toBeFalse()
        ->and($individual->can('delete', $foreign))->toBeFalse();
});

it('renders the translated assign-employees action label', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, [
        'view_any_support_calendar',
        'view_support_calendar',
        'update_support_calendar',
        'update_employee_employee',
    ]);

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);

    Livewire::test(EditCalendar::class, ['record' => $calendar->getRouteKey()])
        ->assertActionVisible('assignEmployees')
        ->assertActionHasLabel('assignEmployees', __('attendance::filament/clusters/configurations/resources/calendar.actions.assign-employees.label'));
});

it('freezes device check-in immutability on edit', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, [
        'view_any_attendance_attendance',
        'view_attendance_attendance',
        'update_attendance_attendance',
        'delete_attendance_attendance',
    ]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'user_id'    => User::factory(),
        'time_zone'  => 'UTC',
    ]);

    $open = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-07',
        'check_in'    => '2026-09-07 08:00:00',
        'source'      => 'biometric-attendance',
        'company_id'  => $company->id,
    ]);

    $rawCheckIn = $open->getRawOriginal('check_in');

    Livewire::test(EditAttendance::class, ['record' => $open->getRouteKey()])
        ->set('data.check_in', '2026-09-07 01:00:00')
        ->set('data.check_out', '2026-09-07 17:00:00')
        ->call('save')
        ->assertHasNoFormErrors();

    $open->refresh();

    expect($open->getRawOriginal('check_in'))->toBe($rawCheckIn)
        ->and($open->check_out)->not->toBeNull();

    Livewire::test(ListAttendances::class)
        ->assertTableActionHidden('edit', $open);
});

it('enforces individual ownership scope across overview queries widgets and exports', function () {
    $company = CompanyHelper::company();

    $individual = hardeningCompanyUser($company, [
        'page_attendance_attendance_overview',
        'view_any_attendance_attendance',
        'view_attendance_attendance',
    ]);

    $individual->forceFill(['resource_permission' => PermissionType::INDIVIDUAL])->saveQuietly();
    hardeningResetBouncer();

    $own = Employee::factory()->create([
        'company_id' => $company->id,
        'user_id'    => $individual->id,
    ]);

    $foreignCreator = User::factory()->create();

    $foreign = Employee::factory()->create([
        'company_id' => $company->id,
        'user_id'    => User::factory(),
    ]);

    $today = Carbon::today()->toDateString();

    Attendance::create([
        'employee_id' => $own->id,
        'work_date'   => $today,
        'check_in'    => $today.' 08:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
        'creator_id'  => $individual->id,
    ]);

    Attendance::create([
        'employee_id' => $foreign->id,
        'work_date'   => $today,
        'check_in'    => $today.' 08:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
        'creator_id'  => $foreignCreator->id,
    ]);

    expect(AttendancePolicy::scopeEmployeeQuery(Employee::query())->pluck('id')->all())
        ->toContain($own->id)
        ->not->toContain($foreign->id)
        ->and(TodayAttendanceWidget::todayStats()['rows'])->toBe(1)
        ->and(AttendanceStatsWidget::monthlyStats()['records'])->toBe(1)
        ->and(AbsenceReportExporter::scopeQuery(Employee::query(), ['employee_id' => [$foreign->id]])->pluck('id')->all())->toBe([])
        ->and(AttendanceExporter::scopeQuery(Attendance::query(), [])->pluck('employee_id')->unique()->values()->all())->toBe([$own->id]);
});

it('applies company default shift holidays to inheriting employees without leaking them to other shifts', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, ['page_attendance_attendance_overview']);

    $default = Calendar::factory()->create([
        'company_id' => $company->id,
        'is_active'  => true,
        'timezone'   => 'UTC',
    ]);

    CalendarAttendance::create([
        'name'        => 'Default monday',
        'day_of_week' => 'monday',
        'day_period'  => 'morning',
        'calendar_id' => $default->id,
        'hour_from'   => 8,
        'hour_to'     => 17,
    ]);

    $other = Calendar::factory()->create([
        'company_id' => $company->id,
        'is_active'  => true,
        'timezone'   => 'UTC',
    ]);

    CalendarAttendance::create([
        'name'        => 'Other monday',
        'day_of_week' => 'monday',
        'day_period'  => 'morning',
        'calendar_id' => $other->id,
        'hour_from'   => 8,
        'hour_to'     => 17,
    ]);

    $inheriting = Employee::factory()->create([
        'company_id'  => $company->id,
        'calendar_id' => null,
        'time_zone'   => 'UTC',
        'user_id'     => User::factory(),
    ]);

    $otherShift = Employee::factory()->create([
        'company_id'  => $company->id,
        'calendar_id' => $other->id,
        'time_zone'   => 'UTC',
        'user_id'     => User::factory(),
    ]);

    CalendarLeave::create([
        'name'        => 'Company Default Day',
        'time_type'   => 'leave',
        'date_from'   => '2026-09-07 00:00:00',
        'date_to'     => '2026-09-07 23:59:59',
        'calendar_id' => $default->id,
        'company_id'  => $company->id,
    ]);

    $entries = app(AttendanceOverviewService::class)->daily(
        Employee::whereIn('id', [$inheriting->id, $otherShift->id])->get(),
        Carbon::parse('2026-09-07'),
        Carbon::parse('2026-09-07'),
    )->keyBy('employee_id');

    expect($entries[$inheriting->id]['status'])->toBe(AttendanceOverviewService::STATUS_HOLIDAY)
        ->and($entries[$inheriting->id]['leave_label'])->toBe('Company Default Day')
        ->and($entries[$otherShift->id]['status'])->toBe(AttendanceOverviewService::STATUS_ABSENT);
});

it('expands the department filter to child sub-departments', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, ['page_attendance_attendance_overview']);

    $parent = Department::factory()->create(['company_id' => $company->id]);
    $child = Department::factory()->create([
        'company_id' => $company->id,
        'parent_id'  => $parent->id,
    ]);

    $nested = Employee::factory()->create([
        'company_id'    => $company->id,
        'department_id' => $child->id,
        'user_id'       => User::factory(),
    ]);

    expect(AttendanceOverviewService::scopeByDepartment(Employee::query(), $parent->id)->pluck('id')->all())
        ->toContain($nested->id)
        ->and(AttendanceOverviewService::scopeByDepartment(Employee::query(), [$parent->id])->pluck('id')->all())
        ->toContain($nested->id);
});

it('filters sessions by open closed and long status', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, ['view_any_attendance_attendance']);

    $employee = Employee::factory()->create(['company_id' => $company->id, 'user_id' => User::factory()]);

    $open = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-07',
        'check_in'    => '2026-09-07 08:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
    ]);

    $closed = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-08',
        'check_in'    => '2026-09-08 08:00:00',
        'check_out'   => '2026-09-08 17:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
    ]);

    $long = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-09',
        'check_in'    => '2026-09-09 08:00:00',
        'check_out'   => '2026-09-10 02:00:00',
        'source'      => 'manual',
        'company_id'  => $company->id,
    ]);

    Livewire::test(ListAttendances::class)
        ->filterTable('session_status', 'open')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$closed, $long])
        ->filterTable('session_status', 'closed')
        ->assertCanSeeTableRecords([$closed, $long])
        ->assertCanNotSeeTableRecords([$open])
        ->filterTable('session_status', 'long')
        ->assertCanSeeTableRecords([$long])
        ->assertCanNotSeeTableRecords([$open, $closed]);
});

it('links dashboard stats to the filtered attendance table', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, [
        'page_attendance_attendance_overview',
        'view_any_attendance_attendance',
    ]);

    $stats = fn (object $widget): array => collect(
        (new ReflectionMethod($widget, 'getStats'))->invoke($widget),
    )->map(fn ($stat): array => [
        'label' => (string) $stat->getLabel(),
        'url'   => $stat->getUrl(),
    ])->all();

    $today = $stats(app(TodayAttendanceWidget::class));
    $month = $stats(app(AttendanceStatsWidget::class));

    $openToday = collect($today)->firstWhere('label', __('attendance::filament/widgets/today-attendance.stats.open'));
    $openMonth = collect($month)->firstWhere('label', __('attendance::filament/widgets/attendance-stats.stats.open'));

    expect($openToday['url'])->toContain('session_status')
        ->and($openToday['url'])->toContain('today')
        ->and($openMonth['url'])->toContain('session_status')
        ->and($openMonth['url'])->toContain('this_month')
        ->and(collect($today)->firstWhere('label', __('attendance::filament/widgets/today-attendance.stats.present'))['url'])
        ->toContain('work_date');
});

it('searches eligible employees lazily with a bounded result set', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, ['page_attendance_attendance_overview']);

    $settings = app(AttendanceSettings::class);
    $settings->employee_scope = AttendanceSettings::SCOPE_ALL;
    $settings->save();

    foreach (['Overview Lazy A', 'Overview Lazy B', 'Overview Lazy C'] as $name) {
        Employee::factory()->create(['company_id' => $company->id, 'name' => $name, 'user_id' => User::factory()]);
    }

    expect(AttendanceOverviewService::searchEligibleEmployees('Overview Lazy', 2))->toHaveCount(2)
        ->and(AttendanceOverviewService::searchEligibleEmployees('Overview Lazy B'))->toHaveCount(1);
});

it('filters absence exports by multiple employees and counts them in the filename', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, ['page_attendance_attendance_overview']);

    $first = Employee::factory()->create(['company_id' => $company->id, 'name' => 'Alpha Employee', 'user_id' => User::factory()]);
    $second = Employee::factory()->create(['company_id' => $company->id, 'name' => 'Beta Employee', 'user_id' => User::factory()]);
    $third = Employee::factory()->create(['company_id' => $company->id, 'name' => 'Gamma Employee', 'user_id' => User::factory()]);

    $scoped = AbsenceReportExporter::scopeQuery(Employee::query(), [
        'employee_id' => [$first->id, $second->id],
        'date_from'   => '2026-09-01',
        'date_to'     => '2026-09-15',
    ])->pluck('id')->sort()->values()->all();

    expect($scoped)->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        ->and($scoped)->not->toContain($third->id);

    $columnMap = collect(AbsenceReportExporter::getColumns())
        ->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])->all();

    $exporter = (new Export(['exporter' => AbsenceReportExporter::class]))->getExporter($columnMap, [
        'employee_id' => [$first->id, $second->id],
        'date_from'   => '2026-09-01',
        'date_to'     => '2026-09-15',
    ]);

    expect($exporter->getFileName(new Export))
        ->toBe('absence-summary_2026-09-01_to_2026-09-15_emp-2');

    $single = (new Export(['exporter' => AbsenceReportExporter::class]))->getExporter($columnMap, [
        'employee_id' => $first->id,
        'date_from'   => '2026-09-01',
        'date_to'     => '2026-09-15',
    ]);

    expect($single->getFileName(new Export))
        ->toBe('absence-summary_2026-09-01_to_2026-09-15_emp-alpha-employee');
});

function hardeningShiftDataset(): array
{
    $company = CompanyHelper::company();

    $default = Calendar::factory()->create([
        'company_id' => $company->id,
        'is_active'  => true,
        'timezone'   => 'UTC',
    ]);

    CalendarAttendance::create([
        'name'        => 'Default monday',
        'day_of_week' => 'monday',
        'day_period'  => 'morning',
        'calendar_id' => $default->id,
        'hour_from'   => 8,
        'hour_to'     => 17,
    ]);

    $other = Calendar::factory()->create([
        'company_id' => $company->id,
        'is_active'  => true,
        'timezone'   => 'UTC',
    ]);

    CalendarAttendance::create([
        'name'        => 'Other monday',
        'day_of_week' => 'monday',
        'day_period'  => 'morning',
        'calendar_id' => $other->id,
        'hour_from'   => 8,
        'hour_to'     => 17,
    ]);

    $direct = Employee::factory()->create([
        'company_id'  => $company->id,
        'calendar_id' => $default->id,
        'user_id'     => User::factory(),
    ]);

    $inherited = Employee::factory()->create([
        'company_id'  => $company->id,
        'calendar_id' => null,
        'user_id'     => User::factory(),
    ]);

    return compact('company', 'default', 'other', 'direct', 'inherited');
}

it('detects the default shift and counts its inherited employees', function () {
    ['company' => $company, 'default' => $default, 'other' => $other, 'inherited' => $inherited] = hardeningShiftDataset();

    hardeningCompanyUser($company, ['page_attendance_attendance_overview']);

    $settings = app(AttendanceSettings::class);
    $settings->employee_scope = AttendanceSettings::SCOPE_ALL;
    $settings->save();

    expect(AttendanceOverviewService::isDefaultCalendar($default))->toBeTrue()
        ->and(AttendanceOverviewService::isDefaultCalendar($other))->toBeFalse()
        ->and(AttendanceOverviewService::inheritedEmployeesCount($default))->toBe(1)
        ->and(AttendanceOverviewService::inheritedEmployeesCount($other))->toBe(0);

    expect(AttendanceOverviewService::scopeEligibleEmployees(Employee::query())
        ->whereKey($inherited->id)
        ->exists())->toBeTrue();
});

it('summarizes direct and inherited employees and links them from the shift tab', function () {
    ['company' => $company, 'default' => $default, 'other' => $other, 'direct' => $direct, 'inherited' => $inherited] = hardeningShiftDataset();

    hardeningCompanyUser($company, [
        'view_any_support_calendar',
        'view_support_calendar',
        'update_support_calendar',
        'update_employee_employee',
        'view_any_employee_employee',
        'view_employee_employee',
    ]);

    $settings = app(AttendanceSettings::class);
    $settings->employee_scope = AttendanceSettings::SCOPE_ALL;
    $settings->save();

    $component = Livewire::test(EmployeesRelationManager::class, [
        'ownerRecord' => $default,
        'pageClass'   => EditCalendar::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$direct])
        ->assertCanNotSeeTableRecords([$inherited]);

    expect(EmployeesRelationManager::getBadge($default, EditCalendar::class))->toBe('1')
        ->and($component->instance()->getTable()->getDescription())->toBe(
            __('attendance::filament/clusters/configurations/resources/calendar/relation-managers/employees.default-shift-summary', [
                'direct'    => 1,
                'inherited' => 1,
                'total'     => 2,
            ]),
        );

    $nameColumn = collect($component->instance()->getTable()->getColumns())
        ->first(fn (object $column): bool => $column->getName() === 'name');

    expect($nameColumn->record($direct)->getUrl())
        ->toBe(EmployeeResource::getUrl('view', ['record' => $direct]));

    Livewire::test(EmployeesRelationManager::class, [
        'ownerRecord' => $other,
        'pageClass'   => EditCalendar::class,
    ])
        ->assertOk()
        ->assertCanNotSeeTableRecords([$direct, $inherited]);

    expect(EmployeesRelationManager::getBadge($other, EditCalendar::class))->toBeNull();
});

it('keeps the attendance settings sub-group iconless inside the settings cluster', function () {
    $group = ManageAttendanceSettings::getNavigationGroup();

    expect($group)->toBeString()
        ->and($group)->toBe(__('admin.navigation.attendance'))
        ->and($group)->not->toBeInstanceOf(HasIcon::class);
});

it('rosters overnight shifts and keeps their start date in non-UTC employee zones', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, ['page_attendance_attendance_overview']);

    $calendar = Calendar::factory()->create(['company_id' => $company->id, 'timezone' => 'UTC']);

    CalendarAttendance::create([
        'name'        => 'Night',
        'day_of_week' => 'monday',
        'day_period'  => 'morning',
        'calendar_id' => $calendar->id,
        'hour_from'   => '22:00',
        'hour_to'     => '06:00',
    ]);

    $employee = Employee::factory()->create([
        'company_id'  => $company->id,
        'calendar_id' => $calendar->id,
        'time_zone'   => 'Asia/Riyadh',
        'user_id'     => User::factory(),
    ]);

    $intervals = $calendar->getAttendanceIntervalsBatch(
        Carbon::parse('2026-09-07 00:00:00', 'UTC'),
        Carbon::parse('2026-09-08 23:59:59', 'UTC'),
        timezone: 'UTC',
    )[null];

    expect($intervals)->not->toBeEmpty()
        ->and($intervals[0][0]->toDateTimeString())->toBe('2026-09-07 22:00:00')
        ->and($intervals[0][1]->toDateTimeString())->toBe('2026-09-08 06:00:00');

    $entries = app(AttendanceOverviewService::class)->daily(
        Employee::query()->whereKey($employee->id)->get(),
        Carbon::parse('2026-09-07'),
        Carbon::parse('2026-09-07'),
    );

    expect($entries->pluck('date')->all())->toBe(['2026-09-07']);
});

it('counts a half-day leave saved without an end date as on leave, not absent', function () {
    TestBootstrapHelper::ensurePluginInstalled('time-off');

    $company = CompanyHelper::company();

    hardeningCompanyUser($company, ['page_attendance_attendance_overview']);

    $calendar = Calendar::factory()->create(['company_id' => $company->id, 'timezone' => 'UTC']);

    CalendarAttendance::create([
        'name'        => 'Tuesday',
        'day_of_week' => 'tuesday',
        'day_period'  => 'morning',
        'calendar_id' => $calendar->id,
        'hour_from'   => 8,
        'hour_to'     => 17,
    ]);

    $employee = Employee::factory()->create([
        'company_id'  => $company->id,
        'calendar_id' => $calendar->id,
        'time_zone'   => 'UTC',
        'user_id'     => User::factory(),
    ]);

    $type = LeaveType::create([
        'name'                  => 'Half Day Test Leave',
        'leave_validation_type' => 'both',
        'company_id'            => $company->id,
    ]);

    Leave::create([
        'employee_id'       => $employee->id,
        'holiday_status_id' => $type->id,
        'date_from'         => '2026-09-08 00:00:00',
        'date_to'           => null,
        'request_date_from' => '2026-09-08',
        'request_date_to'   => null,
        'request_unit_half' => true,
        'number_of_days'    => 0.5,
        'state'             => State::VALIDATE_TWO,
        'company_id'        => $company->id,
    ]);

    $service = app(AttendanceOverviewService::class);
    $employees = Employee::query()->whereKey($employee->id)->get();

    $entry = $service->daily($employees, Carbon::parse('2026-09-08'), Carbon::parse('2026-09-08'))->first();

    expect($entry)->not->toBeNull()
        ->and($entry['status'])->toBe(AttendanceOverviewService::STATUS_ON_LEAVE)
        ->and($entry['half_day'])->toBeTrue()
        ->and($entry['status'])->not->toBe(AttendanceOverviewService::STATUS_ABSENT);

    $register = $service->register($employees, Carbon::parse('2026-09-08'), Carbon::parse('2026-09-08'))->first();

    expect($register['cells']['2026-09-08'])->toBe(AttendanceOverviewService::STATUS_HALF_DAY);
});

it('fills the half-day end date when the leave form omits it', function () {
    TestBootstrapHelper::ensurePluginInstalled('time-off');

    $company = CompanyHelper::company();

    hardeningCompanyUser($company, []);

    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $type = LeaveType::create([
        'name'                  => 'Half Day Form Leave',
        'leave_validation_type' => 'both',
        'company_id'            => $company->id,
    ]);

    $helper = new class
    {
        use TimeOffHelper;
    };

    $data = $helper->mutateTimeOffData([
        'employee_id'       => $employee->id,
        'holiday_status_id' => $type->id,
        'request_date_from' => '2026-09-08',
        'request_date_to'   => null,
        'request_unit_half' => true,
    ]);

    expect($data['request_date_to'])->toBe('2026-09-08')
        ->and($data['date_to'])->toBe('2026-09-08');
});

it('starts a second work block after an explicit check-out', function () {
    $employee = Employee::factory()->create(['time_zone' => 'UTC']);

    EventGateway::push($employee->id, '2026-10-01 08:00:00', AttendanceEvent::DIRECTION_IN, 'biometric-attendance:1', 'in-1');
    EventGateway::push($employee->id, '2026-10-01 12:00:00', AttendanceEvent::DIRECTION_OUT, 'biometric-attendance:1', 'out-1');
    EventGateway::push($employee->id, '2026-10-01 14:00:00', AttendanceEvent::DIRECTION_IN, 'biometric-attendance:1', 'in-2');
    EventGateway::push($employee->id, '2026-10-01 18:00:00', AttendanceEvent::DIRECTION_OUT, 'biometric-attendance:1', 'out-2');

    Artisan::call('attendance:process-events');

    $rows = Attendance::where('employee_id', $employee->id)->orderBy('check_in')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->getRawOriginal('check_in'))->toBe('2026-10-01 08:00:00')
        ->and($rows[0]->getRawOriginal('check_out'))->toBe('2026-10-01 12:00:00')
        ->and($rows[1]->getRawOriginal('check_in'))->toBe('2026-10-01 14:00:00')
        ->and($rows[1]->getRawOriginal('check_out'))->toBe('2026-10-01 18:00:00');
});

it('does not move an existing checkout onto a newly opened older session', function () {
    $employee = Employee::factory()->create(['time_zone' => 'UTC']);

    EventGateway::push($employee->id, '2026-10-02 08:00:00', AttendanceEvent::DIRECTION_IN, 'biometric-attendance:1', 'in-2');
    EventGateway::push($employee->id, '2026-10-02 17:00:00', AttendanceEvent::DIRECTION_OUT, 'biometric-attendance:1', 'out-2');

    Artisan::call('attendance:process-events');

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(1);

    EventGateway::push($employee->id, '2026-10-01 08:00:00', AttendanceEvent::DIRECTION_IN, 'biometric-attendance:1', 'in-1');

    Artisan::call('attendance:process-events');

    $rows = Attendance::where('employee_id', $employee->id)->orderBy('check_in')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->getRawOriginal('check_in'))->toBe('2026-10-01 08:00:00')
        ->and($rows[0]->getRawOriginal('check_out'))->toBeNull()
        ->and($rows[1]->getRawOriginal('check_in'))->toBe('2026-10-02 08:00:00')
        ->and($rows[1]->getRawOriginal('check_out'))->toBe('2026-10-02 17:00:00');
});

it('pairs events for employees outside the acting company scope', function () {
    $companyA = CompanyHelper::company();
    $companyB = CompanyHelper::company();

    hardeningCompanyUser($companyA, ['page_attendance_attendance_overview']);

    $employee = Employee::withoutEvents(fn (): Employee => Employee::factory()->create([
        'company_id' => $companyB->id,
        'time_zone'  => 'UTC',
    ]));

    EventGateway::push($employee->id, '2026-10-01 08:00:00', AttendanceEvent::DIRECTION_IN, 'biometric-attendance:1', 'cross-company');

    Artisan::call('attendance:process-events');

    expect(Attendance::withoutGlobalScope(CompanyScope::class)->where('employee_id', $employee->id)->count())->toBe(1);
});

it('previews the same employee count that assignment applies across sub-departments', function () {
    $company = CompanyHelper::company();

    hardeningCompanyUser($company, [
        'view_any_support_calendar',
        'view_support_calendar',
        'update_support_calendar',
        'update_employee_employee',
    ]);

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);
    $parent = Department::factory()->create(['company_id' => $company->id]);
    $child = Department::factory()->create([
        'company_id' => $company->id,
        'parent_id'  => $parent->id,
    ]);

    Employee::factory()->create([
        'company_id'    => $company->id,
        'department_id' => $parent->id,
        'user_id'       => null,
    ]);

    Employee::factory()->create([
        'company_id'    => $company->id,
        'department_id' => $child->id,
        'user_id'       => null,
    ]);

    Livewire::test(EditCalendar::class, ['record' => $calendar->getRouteKey()])
        ->mountAction('assignEmployees')
        ->setActionData(['department_id' => $parent->id])
        ->assertSchemaStateSet(['preview_count' => 2]);

    Livewire::test(EditCalendar::class, ['record' => $calendar->getRouteKey()])
        ->callAction('assignEmployees', ['department_id' => $parent->id])
        ->assertHasNoActionErrors();

    expect(Employee::query()->whereNotNull('calendar_id')->count())->toBe(2);
});

it('scopes the manual attendance employee picker to the individual owner', function () {
    $company = CompanyHelper::company();

    $individual = hardeningCompanyUser($company, [
        'view_any_attendance_attendance',
        'create_attendance_attendance',
    ]);

    $individual->forceFill(['resource_permission' => PermissionType::INDIVIDUAL])->saveQuietly();
    hardeningResetBouncer();

    $own = Employee::factory()->create([
        'company_id' => $company->id,
        'user_id'    => $individual->id,
    ]);

    Employee::factory()->create([
        'company_id' => $company->id,
        'user_id'    => User::factory(),
    ]);

    $component = Livewire::test(CreateAttendance::class);

    $field = $component->instance()->getSchema('form')->getComponent('employee_id');

    $ids = array_map('intval', array_keys($field->getSearchResultsFromRelationship('')));

    expect($ids)->toBe([$own->id]);
});
