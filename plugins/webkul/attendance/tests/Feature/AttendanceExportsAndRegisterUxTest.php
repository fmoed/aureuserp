<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/CompanyHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Webkul\Attendance\Filament\Exports\AbsenceDetailExporter;
use Webkul\Attendance\Filament\Exports\AbsenceReportExporter;
use Webkul\Attendance\Filament\Pages\AttendanceOverview;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Tables\AttendancesTable;
use Webkul\Attendance\Filament\Tables\AttendanceRegisterTable;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;
use Webkul\Support\Models\Company;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');
    TestBootstrapHelper::ensurePluginInstalled('time-off');
});

function uxRegisterUser(?Company $company = null): User
{
    $company ??= CompanyHelper::company();

    $user = FilamentHelper::actingAsCompanyUser($company, [
        'page_attendance_attendance_overview',
        'view_any_attendance_attendance',
    ]);

    $user->forceFill(['is_active' => true])->saveQuietly();

    return $user;
}

function uxCalendar(array $attributes = []): Calendar
{
    $calendar = Calendar::factory()->create(array_merge([
        'name'      => 'UX Shift',
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

function uxEmployee(Calendar $calendar, string $name = 'UX Employee', ?int $companyId = null): Employee
{
    return Employee::factory()->create([
        'name'        => $name,
        'user_id'     => User::factory(),
        'time_zone'   => 'UTC',
        'calendar_id' => $calendar->id,
        'company_id'  => $companyId,
    ]);
}

it('formats minutes as register hours with optional signs', function () {
    expect(AttendanceRegisterTable::formatMinutesToHours(9600))->toBe('160:00')
        ->and(AttendanceRegisterTable::formatMinutesToHours(270, true))->toBe('+04:30')
        ->and(AttendanceRegisterTable::formatMinutesToHours(-135, true))->toBe('-02:15')
        ->and(AttendanceRegisterTable::formatMinutesToHours(0, true))->toBe('00:00')
        ->and(AttendanceRegisterTable::formatCount(21.5))->toBe('21.5')
        ->and(AttendanceRegisterTable::formatCount(20.0))->toBe('20');
});

it('pairs worked days and hours in a yearly month cell', function () {
    $cell = [
        'present'           => 20,
        'absent'            => 2,
        'on_leave'          => 1,
        'minutes'           => 9480,
        'scheduled_minutes' => 9600,
        'overtime_minutes'  => 60,
    ];

    expect(AttendanceRegisterTable::formatYearCell($cell))->toBe('20d · 158h')
        ->and(AttendanceRegisterTable::yearCellDetails('2026-09-01', $cell))
        ->toContain('September 2026')
        ->toContain('Present days: 20')
        ->toContain('Worked hours: 158:00')
        ->toContain('Scheduled hours: 160:00')
        ->toContain('Overtime: 01:00');
});

it('renders the variance and scheduled hours columns on the totals register', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        $company = CompanyHelper::company();
        uxRegisterUser($company);

        $calendar = uxCalendar(['company_id' => $company->id]);
        $employee = uxEmployee($calendar, 'UX Employee', $company->id);

        Attendance::create([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-07',
            'check_in'    => '2026-09-07 08:30:00',
            'check_out'   => '2026-09-07 17:00:00',
            'source'      => 'manual',
            'company_id'  => $company->id,
        ]);

        $component = Livewire::test(AttendanceOverview::class)
            ->call('showRegisterView', 'totals');

        $names = collect($component->instance()->getTable()->getColumns())->map->getName()->all();

        expect($names)->toContain('totals.scheduled_minutes')
            ->and($names)->toContain('totals.minutes')
            ->and($names)->toContain('totals.overtime_minutes')
            ->and($names)->toContain('totals.late_minutes')
            ->and($names)->toContain('totals.variance_minutes')
            ->and($names)->toContain('totals.missing_checkouts');

        $varianceColumn = collect($component->instance()->getTable()->getColumns())
            ->first(fn (object $column): bool => $column->getName() === 'totals.variance_minutes');

        expect($varianceColumn->formatState(-30))->toBe('-00:30')
            ->and($varianceColumn->getColor(-30))->toBe('danger')
            ->and($varianceColumn->getColor(120))->toBe('success');

        $hours = $component->instance()->getHoursSummary();

        expect($hours['worked'])->toBe(450)
            ->and($hours['scheduled'])->toBe(5280)
            ->and($hours['late'])->toBe(30);
    } finally {
        Carbon::setTestNow();
    }
});

it('renders monthly day-and-hours cells in the yearly register', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        $company = CompanyHelper::company();
        uxRegisterUser($company);

        $calendar = uxCalendar(['company_id' => $company->id]);
        $employee = uxEmployee($calendar, 'UX Employee', $company->id);

        Attendance::create([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-07',
            'check_in'    => '2026-09-07 08:00:00',
            'check_out'   => '2026-09-07 17:00:00',
            'source'      => 'manual',
        ]);

        $component = Livewire::test(AttendanceOverview::class)
            ->call('showRegisterView', 'totals')
            ->fillForm([
                'view_mode' => 'year',
                'year'      => 2026,
            ], 'filterForm')
            ->call('applyFilters');

        $columns = collect($component->instance()->getTable()->getColumns());

        expect($columns->map->getName()->all())
            ->toContain('months.2026-09-01')
            ->toContain('totals.present')
            ->toContain('totals.absent')
            ->toContain('totals.minutes');

        $september = $columns->first(fn (object $column): bool => $column->getName() === 'months.2026-09-01');

        expect($september->formatState([
            'present'           => 1,
            'absent'            => 0,
            'on_leave'          => 0,
            'minutes'           => 480,
            'scheduled_minutes' => 480,
            'overtime_minutes'  => 0,
        ]))->toBe('1d · 8h');
    } finally {
        Carbon::setTestNow();
    }
});

it('renders the overnight marker and estimated badge on the attendance table', function () {
    uxRegisterUser();

    $company = Company::whereKey(current_company_id())->firstOrFail();
    $calendar = uxCalendar();
    $employee = uxEmployee($calendar, 'Night Worker', $company->id);

    $night = Attendance::create([
        'employee_id'  => $employee->id,
        'work_date'    => '2026-10-05',
        'check_in'     => '2026-10-05 22:00:00',
        'check_out'    => '2026-10-06 04:00:00',
        'source'       => 'manual',
        'is_estimated' => false,
    ]);

    $estimated = Attendance::create([
        'employee_id'  => $employee->id,
        'work_date'    => '2026-10-06',
        'check_in'     => '2026-10-06 08:00:00',
        'check_out'    => null,
        'source'       => 'manual',
        'is_estimated' => true,
    ]);

    expect(AttendancesTable::crossesMidnight($night))->toBeTrue()
        ->and(AttendancesTable::crossesMidnight($estimated))->toBeFalse();

    $columns = collect(Livewire::test(ListAttendances::class)->instance()->getTable()->getColumns());

    $nextDay = $columns->first(fn (object $column): bool => $column->getName() === 'next_day');
    $estimatedColumn = $columns->first(fn (object $column): bool => $column->getName() === 'is_estimated');

    expect($nextDay)->not->toBeNull()
        ->and($estimatedColumn)->not->toBeNull()
        ->and($nextDay->record($night)->getState())->toBe('(+1d)')
        ->and($nextDay->record($estimated)->getState())->toBeNull()
        ->and($estimatedColumn->record($estimated)->getState())->toBe('Estimated')
        ->and($estimatedColumn->record($night)->getState())->toBeNull();
});

it('shows the new statuses in the register legend and cell codes', function () {
    expect(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_MISSING_CHECKOUT))->toBe('!')
        ->and(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_WEEKEND_PRESENT))->toBe('W+')
        ->and(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_HOLIDAY_PRESENT))->toBe('H+')
        ->and(AttendanceRegisterTable::statusShortLabel(AttendanceOverviewService::STATUS_HALF_DAY))->toBe('½')
        ->and(AttendanceRegisterTable::statusColor(AttendanceOverviewService::STATUS_MISSING_CHECKOUT))->toBe('warning')
        ->and(AttendanceRegisterTable::statusColor(AttendanceOverviewService::STATUS_WEEKEND_PRESENT))->toBe('success')
        ->and(AttendanceRegisterTable::statusColor(AttendanceOverviewService::STATUS_HOLIDAY_PRESENT))->toBe('success');

    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        uxRegisterUser();

        $company = Company::whereKey(current_company_id())->firstOrFail();
        $calendar = uxCalendar();
        uxEmployee($calendar, 'UX Legend', $company->id);

        $legend = collect(Livewire::test(AttendanceOverview::class)->instance()->getLegend());

        expect($legend->pluck('key')->all())
            ->toContain(
                AttendanceOverviewService::STATUS_MISSING_CHECKOUT,
                AttendanceOverviewService::STATUS_WEEKEND_PRESENT,
                AttendanceOverviewService::STATUS_HOLIDAY_PRESENT,
            );
    } finally {
        Carbon::setTestNow();
    }
});

it('describes day cells with hours, breaks and an overnight marker', function () {
    $details = AttendanceRegisterTable::dayDetails('present', [
        'check_in'            => '2026-10-05 22:00:00',
        'check_out'           => '2026-10-06 04:00:00',
        'minutes'             => 300,
        'scheduled_minutes'   => 360,
        'break_minutes'       => 60,
        'late_minutes'        => 15,
        'early_leave_minutes' => 0,
        'overtime_minutes'    => 30,
    ], '2026-10-05');

    expect($details)
        ->toContain('22:00 → 04:00 (+1d)')
        ->toContain('05:00 / 06:00')
        ->toContain('Break: 60m')
        ->toContain('Late: 15m')
        ->toContain('Overtime: 00:30');
});

it('exposes the detailed hours columns on the export surfaces', function () {
    $summaryColumns = collect(AbsenceReportExporter::getColumns())->map->getName()->all();

    expect($summaryColumns)->toContain('scheduled_minutes', 'minutes', 'overtime_minutes', 'late_minutes', 'variance_minutes');

    $detailColumns = collect(AbsenceDetailExporter::getColumns())->map->getName()->all();

    expect($detailColumns)->toContain(
        'scheduled-minutes',
        'worked-minutes',
        'overtime-minutes',
        'late-minutes',
        'early-leave-minutes',
        'variance-minutes',
    );
});
