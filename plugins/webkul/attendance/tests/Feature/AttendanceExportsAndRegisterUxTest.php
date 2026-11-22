<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/CompanyHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use Filament\Actions\Exports\Models\Export;
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
use Webkul\TimeOff\Enums\AllocationType;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;

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
            ->and($names)->toContain('totals.shortfall_minutes')
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

    expect($summaryColumns)->toContain('scheduled_minutes', 'minutes', 'overtime_minutes', 'shortfall_minutes', 'late_minutes', 'variance_minutes');

    $detailColumns = collect(AbsenceDetailExporter::getColumns())->map->getName()->all();

    expect($detailColumns)->toContain(
        'remaining-leave',
        'scheduled-minutes',
        'worked-minutes',
        'overtime-minutes',
        'shortfall-minutes',
        'late-minutes',
        'early-leave-minutes',
        'variance-minutes',
    );
});

it('places overview exports in toolbar, syncs filters/search/columns, and reports remaining leave', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        $company = CompanyHelper::company();
        uxRegisterUser($company);

        $calendar = uxCalendar(['company_id' => $company->id]);
        $alpha = uxEmployee($calendar, 'Alpha Synced', $company->id);
        $beta = uxEmployee($calendar, 'Beta Excluded', $company->id);

        $annual = LeaveType::create(['name' => 'Annual Leave', 'leave_validation_type' => 'both', 'company_id' => $company->id]);
        $sick = LeaveType::create(['name' => 'Sick Leave', 'leave_validation_type' => 'both', 'company_id' => $company->id]);

        foreach ([[$annual, 20], [$sick, 10]] as [$type, $days]) {
            LeaveAllocation::create([
                'holiday_status_id'   => $type->id,
                'employee_id'         => $alpha->id,
                'employee_company_id' => $company->id,
                'company_id'          => $company->id,
                'name'                => $type->name.' 2026',
                'state'               => State::VALIDATE_TWO->value,
                'allocation_type'     => AllocationType::REGULAR->value,
                'date_from'           => '2026-01-01',
                'date_to'             => '2026-12-31',
                'number_of_days'      => $days,
            ]);
        }

        // Approved 1d annual + 0.5d sick; refused & pending ignored for balance
        foreach ([
            [$annual->id, '2026-09-07', 1, State::VALIDATE_TWO, false],
            [$sick->id, '2026-09-08', 0.5, State::VALIDATE_TWO, true],
            [$annual->id, '2026-09-09', 1, State::REFUSE, false],
            [$annual->id, '2026-09-10', 1, State::CONFIRM, false],
        ] as [$typeId, $date, $days, $state, $half]) {
            Leave::create([
                'employee_id'       => $alpha->id,
                'holiday_status_id' => $typeId,
                'date_from'         => "{$date} 00:00:00",
                'date_to'           => "{$date} 00:00:00",
                'request_date_from' => $date,
                'request_date_to'   => $date,
                'request_unit_half' => $half,
                'number_of_days'    => $days,
                'state'             => $state,
                'company_id'        => $company->id,
            ]);
        }

        $component = Livewire::test(AttendanceOverview::class);

        expect(collect($component->instance()->getCachedHeaderActions())->map->getName()->all())->not->toContain('export', 'exportDetail')
            ->and(collect($component->instance()->getTable()->getToolbarActions())->map->getName()->all())->toContain('export', 'exportDetail');

        $daysHtml = $component->html();
        expect(strpos($daysHtml, __('attendance::filament/pages/overview.export-detail')))
            ->toBeGreaterThan(strpos($daysHtml, 'fi-ta-header-toolbar'));

        $component->call('showRegisterView', 'totals');
        $remainingCol = collect($component->instance()->getTable()->getColumns())->first(fn (object $c): bool => $c->getName() === 'totals.remaining_leave');

        expect($remainingCol->record($alpha)->getState())->toBe(28.5)
            ->and($remainingCol->record($alpha)->getTooltip())->toContain('Annual Leave: 19 / 20', 'Sick Leave: 9.5 / 10');

        $toggles = collect($component->instance()->tableColumns)->map(fn (array $col): array => match ($col['name']) {
            'totals.workdays'                               => array_merge($col, ['isToggled' => false]),
            'totals.late_minutes', 'totals.remaining_leave' => array_merge($col, ['isToggled' => true]),
            default                                         => $col,
        })->all();

        $component->call('applyTableColumnManager', $toggles)
            ->fillForm(['view_mode' => 'range', 'employee_id' => $alpha->id, 'date_from' => '2026-09-07', 'date_to' => '2026-09-09'], 'filterForm')
            ->call('applyFilters')
            ->mountAction('export')
            ->assertSchemaStateSet(['columnMap.workdays.isEnabled' => false, 'columnMap.late_minutes.isEnabled' => true, 'columnMap.remaining_leave.isEnabled' => true])
            ->callMountedAction();

        $summaryExport = Export::query()->latest('id')->firstOrFail();
        $summaryDisk = $summaryExport->getFileDisk();
        $summaryDir = $summaryExport->getFileDirectory();
        $summaryFile = collect($summaryDisk->files($summaryDir))->first(fn (string $f): bool => str($f)->endsWith('.csv') && ! str($f)->endsWith('headers.csv'));

        expect($summaryDisk->get($summaryDir.DIRECTORY_SEPARATOR.'headers.csv'))->toContain('Late Minutes', 'Remaining Leave')->not->toContain('Total Workdays')
            ->and($summaryDisk->get($summaryFile))->toContain($alpha->name, '28.5');

        $component->call('showRegisterView', 'days')
            ->fillForm(['view_mode' => 'range', 'employee_id' => null, 'date_from' => '2026-09-07', 'date_to' => '2026-09-09'], 'filterForm')
            ->call('applyFilters')
            ->set('tableSearch', 'Alpha Synced');

        $dayToggles = collect($component->instance()->tableColumns)->map(
            fn (array $col): array => in_array($col['name'], ['schedule', 'cells.2026-09-08'], true) ? array_merge($col, ['isToggled' => false]) : $col,
        )->all();

        $component->call('applyTableColumnManager', $dayToggles)
            ->mountAction('exportDetail')
            ->assertSchemaStateSet(['columnMap.schedule.isEnabled' => false, 'columnMap.employee.isEnabled' => true])
            ->callMountedAction();

        $detailExport = Export::query()->latest('id')->firstOrFail();
        $detailDisk = $detailExport->getFileDisk();
        $detailDir = $detailExport->getFileDirectory();
        $detailFile = collect($detailDisk->files($detailDir))->first(fn (string $f): bool => str($f)->endsWith('.csv') && ! str($f)->endsWith('headers.csv'));
        $detailHeaders = str_getcsv(trim(ltrim($detailDisk->get($detailDir.DIRECTORY_SEPARATOR.'headers.csv'), "\xEF\xBB\xBF")));

        expect($detailExport->successful_rows)->toBe(1)
            ->and($detailHeaders)->toContain('2026-09-07 (Mon)', '2026-09-09 (Wed)', 'Remaining Leave')->not->toContain('2026-09-08 (Tue)', 'Schedule')
            ->and($detailDisk->get($detailFile))->toContain($alpha->name, '28.5')->not->toContain($beta->name);
    } finally {
        Carbon::setTestNow();
    }
});
