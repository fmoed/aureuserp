<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/CompanyHelper.php';

use Filament\Actions\Exports\Models\Export;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Webkul\Attendance\Enums\MissingCheckoutPolicy;
use Webkul\Attendance\Enums\ShiftType;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\EditCalendar as CalendarEditPage;
use Webkul\Attendance\Filament\Exports\AttendanceExporter;
use Webkul\Attendance\Filament\Pages\AttendanceOverview;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Pages\CreateAttendance;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Pages\EditAttendance;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Tables\AttendancesTable;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;
use Webkul\Support\Models\CalendarLeave;
use Webkul\TimeOff\Enums\LeaveValidationType;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');
    TestBootstrapHelper::ensurePluginInstalled('time-off');

    WriterRegistry::flush();
    WriterRegistry::register('biometric-attendance');
});

it('excludes inactive employees without attendance records from overview', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        $activeEmp = Employee::factory()->create(['user_id' => User::factory(), 'is_active' => true]);
        $inactiveNoRecords = Employee::factory()->create(['user_id' => User::factory(), 'is_active' => false]);
        $inactiveWithRecord = Employee::factory()->create(['user_id' => User::factory(), 'is_active' => false]);

        Attendance::create([
            'employee_id' => $inactiveWithRecord->id,
            'work_date'   => '2026-09-05',
            'check_in'    => '2026-09-05 08:00:00',
            'check_out'   => '2026-09-05 17:00:00',
            'source'      => 'manual',
            'company_id'  => $inactiveWithRecord->company_id,
        ]);

        $eligibleQuery = AttendanceOverviewService::scopeEligibleEmployees(Employee::query());
        $eligibleIds = $eligibleQuery->pluck('id')->all();

        expect($eligibleIds)->toContain($activeEmp->id)
            ->and($eligibleIds)->toContain($inactiveWithRecord->id)
            ->and($eligibleIds)->not->toContain($inactiveNoRecords->id);
    } finally {
        Carbon::setTestNow();
    }
});

it('does not generate absences for days after employee departure_date', function () {
    Carbon::setTestNow('2026-09-20 12:00:00');

    try {
        $calendar = Calendar::factory()->create([
            'timezone'   => 'UTC',
            'shift_type' => ShiftType::Fixed->value,
        ]);

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
            CalendarAttendance::create([
                'name'        => "{$day} shift",
                'day_of_week' => $day,
                'day_period'  => 'morning',
                'calendar_id' => $calendar->id,
                'hour_from'   => 8,
                'hour_to'     => 16,
            ]);
        }

        // Employee departs on Sept 10, 2026 (Thursday)
        $employee = Employee::factory()->create([
            'user_id'        => User::factory(),
            'is_active'      => true,
            'calendar_id'    => $calendar->id,
            'departure_date' => '2026-09-10',
        ]);

        $service = app(AttendanceOverviewService::class);

        $entries = $service->daily(
            Employee::whereKey($employee->id)->get(),
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-15')
        )->keyBy('date');

        // Monday 2026-09-07 to Thursday 2026-09-10 are active workdays -> absent if not attended
        expect($entries->has('2026-09-07'))->toBeTrue()
            ->and($entries->has('2026-09-08'))->toBeTrue()
            ->and($entries->has('2026-09-09'))->toBeTrue()
            ->and($entries->has('2026-09-10'))->toBeTrue();

        // Friday 2026-09-11 and Monday 2026-09-14 are after departure_date -> must NOT be returned as active workdays/absences
        expect($entries->has('2026-09-11'))->toBeFalse()
            ->and($entries->has('2026-09-14'))->toBeFalse()
            ->and($entries->has('2026-09-15'))->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('allows multiple non-overlapping manual sessions on the same day but rejects overlapping intervals', function () {
    $employee = Employee::factory()->create(['user_id' => User::factory(), 'is_active' => true]);

    // First session: 08:00 to 12:00
    Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-24',
        'check_in'    => '2026-09-24 08:00:00',
        'check_out'   => '2026-09-24 12:00:00',
        'source'      => 'manual',
        'company_id'  => $employee->company_id,
    ]);

    FilamentHelper::actingAs([
        'create_attendance_attendance',
        'view_any_attendance_attendance',
    ]);

    // Second non-overlapping session: 13:00 to 17:00 -> should succeed
    Livewire::test(CreateAttendance::class)
        ->fillForm([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-24',
            'check_in'    => '2026-09-24 13:00:00',
            'check_out'   => '2026-09-24 17:00:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Attendance::where('employee_id', $employee->id)->where('work_date', '2026-09-24')->count())->toBe(2);

    // Overlapping session: 11:30 to 14:00 -> should fail validation on work_date
    Livewire::test(CreateAttendance::class)
        ->fillForm([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-24',
            'check_in'    => '2026-09-24 11:30:00',
            'check_out'   => '2026-09-24 14:00:00',
        ])
        ->call('create')
        ->assertHasFormErrors(['work_date']);
});

it('allows editing closed manual records when date is not locked', function () {
    $employee = Employee::factory()->create(['user_id' => User::factory(), 'is_active' => true]);

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => Carbon::yesterday()->toDateString(),
        'check_in'    => Carbon::yesterday()->setTime(8, 0)->toDateTimeString(),
        'check_out'   => Carbon::yesterday()->setTime(16, 0)->toDateTimeString(),
        'source'      => 'manual',
        'company_id'  => $employee->company_id,
    ]);

    FilamentHelper::actingAs([
        'update_attendance_attendance',
        'view_any_attendance_attendance',
    ]);

    Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
        ->fillForm([
            'check_out' => Carbon::yesterday()->setTime(17, 0)->toDateTimeString(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($attendance->fresh()->check_out->format('H:i'))->toBe('17:00');
});

it('defaults check_out in addCheckout action to scheduled shift end time', function () {
    $calendar = Calendar::factory()->create([
        'timezone'   => 'UTC',
        'shift_type' => ShiftType::Fixed->value,
    ]);

    $dayName = strtolower(Carbon::today()->format('l'));

    CalendarAttendance::create([
        'name'        => "{$dayName} shift",
        'day_of_week' => $dayName,
        'calendar_id' => $calendar->id,
        'hour_from'   => 8,
        'hour_to'     => 17,
    ]);

    $employee = Employee::factory()->create([
        'user_id'     => User::factory(),
        'is_active'   => true,
        'calendar_id' => $calendar->id,
    ]);

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => Carbon::today()->toDateString(),
        'check_in'    => Carbon::today()->setTime(8, 0)->toDateTimeString(),
        'check_out'   => null,
        'source'      => 'manual',
        'company_id'  => $employee->company_id,
    ]);

    FilamentHelper::actingAs([
        'update_attendance_attendance',
        'view_any_attendance_attendance',
    ]);

    Livewire::test(ListAttendances::class)
        ->mountTableAction('add_checkout', $attendance)
        ->assertTableActionDataSet([
            'check_out' => Carbon::today()->setTime(17, 0)->format('Y-m-d H:i:s'),
        ]);
});

it('recalculates summary hours correctly on Calendar', function () {
    $calendar = Calendar::factory()->create([
        'timezone'   => 'UTC',
        'shift_type' => ShiftType::Fixed->value,
    ]);

    // 5 days x 8 hours = 40 hours weekly, 8 hours daily
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
        CalendarAttendance::create([
            'name'        => "{$day} morning",
            'day_of_week' => $day,
            'calendar_id' => $calendar->id,
            'hour_from'   => 8,
            'hour_to'     => 12,
        ]);
        CalendarAttendance::create([
            'name'        => "{$day} afternoon",
            'day_of_week' => $day,
            'calendar_id' => $calendar->id,
            'hour_from'   => 13,
            'hour_to'     => 17,
        ]);
    }

    $calendar->recalculateSummaryHours();
    $calendar->refresh();

    expect((float) $calendar->hours_per_day)->toBe(8.0)
        ->and((float) $calendar->full_time_required_hours)->toBe(40.0);
});

it('mounts inspectDayCellAction slideOver with day evaluation and timeline', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        FilamentHelper::actingAs([
            'page_attendance_attendance_overview',
            'view_any_attendance_attendance',
            'create_attendance_attendance',
            'update_attendance_attendance',
        ])->forceFill(['is_active' => true])->saveQuietly();

        $employee = Employee::factory()->create([
            'user_id'   => User::factory(),
            'is_active' => true,
        ]);

        Attendance::create([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-10',
            'check_in'    => '2026-09-10 08:00:00',
            'check_out'   => '2026-09-10 16:00:00',
            'source'      => 'manual',
            'company_id'  => $employee->company_id,
        ]);

        AttendanceEvent::create([
            'employee_id' => $employee->id,
            'punched_at'  => '2026-09-10 08:00:00',
            'direction'   => AttendanceEvent::DIRECTION_IN,
            'source'      => 'biometric-attendance',
            'company_id'  => $employee->company_id,
        ]);

        Livewire::test(AttendanceOverview::class)
            ->mountAction('inspectDayCell', [
                'employee_id' => $employee->id,
                'date'        => '2026-09-10',
            ])
            ->assertActionMounted('inspectDayCell');
    } finally {
        Carbon::setTestNow();
    }
});

it('supports custom per-day schedules like shorter thursdays without validation failure or data loss', function () {
    FilamentHelper::actingAs([
        'view_any_support_calendar',
        'view_support_calendar',
        'update_support_calendar',
        'update_attendance_calendar',
    ])->forceFill(['is_active' => true])->saveQuietly();

    $calendar = Calendar::factory()->create([
        'name'       => 'Varying Daily Hours Shift',
        'timezone'   => 'UTC',
        'shift_type' => ShiftType::Fixed->value,
    ]);

    // 4 days x 8 hours (Monday-Thursday): 08:00 to 16:00
    foreach (['monday', 'tuesday', 'wednesday', 'thursday'] as $day) {
        CalendarAttendance::create([
            'name'        => "{$day} shift",
            'day_of_week' => $day,
            'calendar_id' => $calendar->id,
            'hour_from'   => 8,
            'hour_to'     => 16,
        ]);
    }

    // Friday x 4 hours: 08:00 to 12:00
    CalendarAttendance::create([
        'name'        => 'friday short shift',
        'day_of_week' => 'friday',
        'calendar_id' => $calendar->id,
        'hour_from'   => 8,
        'hour_to'     => 12,
    ]);

    // Total net hours: 4 * 8 + 4 = 36 hours weekly, 36 / 5 = 7.2 hours daily average
    $calendar->recalculateSummaryHours();
    $calendar->refresh();

    expect((float) $calendar->full_time_required_hours)->toBe(36.0)
        ->and((float) $calendar->hours_per_day)->toBe(7.2);

    // Edit the calendar in CalendarEditPage (e.g. changing name)
    // It must save cleanly without validation errors on hours_per_day, and preserve Friday's 4-hour schedule
    Livewire::test(CalendarEditPage::class, ['record' => $calendar->getRouteKey()])
        ->fillForm([
            'name' => 'Renamed Varying Daily Hours Shift',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $calendar->refresh();
    expect($calendar->name)->toBe('Renamed Varying Daily Hours Shift');

    $fridayAttendance = $calendar->attendance()->where('day_of_week', 'friday')->first();
    expect($fridayAttendance)->not->toBeNull()
        ->and((float) $fridayAttendance->hour_to)->toBe(12.0);
});

it('calculates 8 hours per day and 40 hours per week for a 08:00 to 16:00 shift without subtracting phantom break minutes', function () {
    FilamentHelper::actingAs([
        'view_any_support_calendar',
        'view_support_calendar',
        'update_support_calendar',
        'update_attendance_calendar',
    ])->forceFill(['is_active' => true])->saveQuietly();

    $calendar = Calendar::factory()->create([
        'name'       => 'Standard 8h Shift',
        'timezone'   => 'UTC',
        'shift_type' => ShiftType::Fixed->value,
    ]);

    $component = Livewire::test(CalendarEditPage::class, ['record' => $calendar->getRouteKey()])
        ->fillForm([
            'name'          => 'Standard 8h Shift',
            'timezone'      => 'UTC',
            'shift_type'    => ShiftType::Fixed->value,
            'work_days'     => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'shift_periods' => [
                ['day_period' => 'morning', 'hour_from' => '08:00', 'hour_to' => '16:00'],
            ],
        ]);

    expect((float) $component->get('data.hours_per_day'))->toBe(8.0)
        ->and((float) $component->get('data.full_time_required_hours'))->toBe(40.0);

    $component->call('save')->assertHasNoFormErrors();

    $calendar->refresh();
    expect((float) $calendar->hours_per_day)->toBe(8.0)
        ->and((float) $calendar->full_time_required_hours)->toBe(40.0)
        ->and($calendar->attendance()->count())->toBe(5);
});

it('renders inspectDayCell with TwoExtraLarge width, compact sections, session time range, no duplicate sessions table, and hides zero-minute entries', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        FilamentHelper::actingAs([
            'page_attendance_attendance_overview',
            'view_any_attendance_attendance',
        ])->forceFill(['is_active' => true])->saveQuietly();

        $calendar = Calendar::factory()->create([
            'timezone'   => 'UTC',
            'shift_type' => ShiftType::Fixed->value,
        ]);

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
            CalendarAttendance::create([
                'name'        => "{$day} shift",
                'day_of_week' => $day,
                'day_period'  => 'morning',
                'calendar_id' => $calendar->id,
                'hour_from'   => 8,
                'hour_to'     => 16,
            ]);
        }

        $employee = Employee::factory()->create([
            'user_id'     => User::factory(),
            'is_active'   => true,
            'time_zone'   => 'UTC',
            'calendar_id' => $calendar->id,
        ]);

        // Exact full shift on Thursday 2026-09-10: 08:00 -> 16:00 (all metrics 0)
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-10',
            'check_in'    => '2026-09-10 08:00:00',
            'check_out'   => '2026-09-10 16:00:00',
            'source'      => 'manual',
            'company_id'  => $employee->company_id,
        ]);

        $inEvent = AttendanceEvent::create([
            'employee_id'   => $employee->id,
            'attendance_id' => $attendance->id,
            'punched_at'    => '2026-09-10 08:00:00',
            'direction'     => AttendanceEvent::DIRECTION_IN,
            'source'        => 'biometric-attendance',
            'source_ref'    => 'test-in-1',
            'company_id'    => $employee->company_id,
        ]);

        $outEvent = AttendanceEvent::create([
            'employee_id'   => $employee->id,
            'attendance_id' => $attendance->id,
            'punched_at'    => '2026-09-10 16:00:00',
            'direction'     => AttendanceEvent::DIRECTION_OUT,
            'source'        => 'biometric-attendance',
            'source_ref'    => 'test-out-1',
            'company_id'    => $employee->company_id,
        ]);

        $unknownEvent = AttendanceEvent::create([
            'employee_id'   => $employee->id,
            'attendance_id' => null,
            'punched_at'    => '2026-09-10 12:00:00',
            'direction'     => AttendanceEvent::DIRECTION_UNKNOWN,
            'source'        => 'biometric-attendance',
            'source_ref'    => 'test-unknown-1',
            'company_id'    => $employee->company_id,
        ]);

        $component = Livewire::test(AttendanceOverview::class)
            ->mountAction('inspectDayCell', [
                'employee_id' => $employee->id,
                'date'        => '2026-09-10',
            ])
            ->assertActionMounted('inspectDayCell')
            ->assertDontSee('punch-time')
            ->assertDontSee('0 min');

        $action = $component->instance()->inspectDayCellAction();
        expect($action->getModalWidth())->toBe(Width::TwoExtraLarge);

        $schema = (new ReflectionMethod($component->instance(), 'buildInspectDaySchema'))
            ->invoke($component->instance(), [
                'employee_id' => $employee->id,
                'date'        => '2026-09-10',
            ]);

        $collectComponents = function (array $items) use (&$collectComponents): array {
            $all = [];
            foreach ($items as $item) {
                $all[] = $item;
                if (method_exists($item, 'getDefaultChildComponents')) {
                    array_push($all, ...$collectComponents($item->getDefaultChildComponents()));
                }
            }

            return $all;
        };

        $allComponents = $collectComponents($schema);

        $names = collect($allComponents)
            ->filter(fn (object $c): bool => method_exists($c, 'getName'))
            ->map->getName()
            ->all();

        expect($names)->not->toContain('times');

        $attendancesRepeater = collect($allComponents)->first(fn (object $c): bool => $c instanceof RepeatableEntry && $c->getName() === 'attendances');
        $sessionTimesEntry = collect($allComponents)->first(fn (object $c): bool => method_exists($c, 'getName') && $c->getName() === 'session_times');
        $eventsRepeater = collect($allComponents)->first(fn (object $c): bool => $c instanceof RepeatableEntry && $c->getName() === 'raw_events');

        expect($attendancesRepeater)->toBeNull()
            ->and($sessionTimesEntry)->not->toBeNull()
            ->and($sessionTimesEntry->getState())->toBe("\u{200E}08:00 → 16:00")
            ->and($eventsRepeater)->not->toBeNull()
            ->and($eventsRepeater->isTable())->toBeTrue()
            ->and($eventsRepeater->getExtraAttributes()['class'] ?? '')->toContain('fi-inspect-punches');

        $directionEntry = collect($eventsRepeater->getDefaultChildComponents())
            ->first(fn (object $c): bool => method_exists($c, 'getName') && $c->getName() === 'direction');

        $resolveDirectionColor = function (AttendanceEvent $event) use ($component, $directionEntry): mixed {
            $entry = clone $directionEntry;
            $entry->container(Schema::make($component->instance())->record($event));

            return $entry->getColor($entry->getState());
        };

        expect($directionEntry)->not->toBeNull()
            ->and($resolveDirectionColor($inEvent))->toBe('success')
            ->and($resolveDirectionColor($outEvent))->toBe('warning')
            ->and($resolveDirectionColor($unknownEvent))->toBe('gray');

        foreach (['late', 'early_leave', 'shortfall', 'overtime', 'break'] as $metricName) {
            $entry = collect($allComponents)->first(fn (object $c): bool => method_exists($c, 'getName') && $c->getName() === $metricName);
            expect($entry)->not->toBeNull()
                ->and($entry->isVisible())->toBeFalse();
        }
    } finally {
        Carbon::setTestNow();
    }
});

it('accumulates shortfall_minutes per workday without offsetting by other days overtime and exposes it by default in totals', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        FilamentHelper::actingAs([
            'page_attendance_attendance_overview',
            'view_any_attendance_attendance',
        ])->forceFill(['is_active' => true])->saveQuietly();

        $calendar = Calendar::factory()->create([
            'timezone'   => 'UTC',
            'shift_type' => ShiftType::Fixed->value,
        ]);

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
            CalendarAttendance::create([
                'name'        => "{$day} shift",
                'day_of_week' => $day,
                'calendar_id' => $calendar->id,
                'hour_from'   => 8,
                'hour_to'     => 14,
            ]);
        }

        $employee = Employee::factory()->create([
            'user_id'     => User::factory(),
            'is_active'   => true,
            'time_zone'   => 'UTC',
            'calendar_id' => $calendar->id,
        ]);

        // Monday 2026-09-07: 5 hours worked instead of 6 -> 60m shortfall
        Attendance::create([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-07',
            'check_in'    => '2026-09-07 08:00:00',
            'check_out'   => '2026-09-07 13:00:00',
            'source'      => 'manual',
            'company_id'  => $employee->company_id,
        ]);

        // Tuesday 2026-09-08: 7 hours worked -> 60m overtime, 0m shortfall (must not cancel Monday's shortfall)
        Attendance::create([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-08',
            'check_in'    => '2026-09-08 08:00:00',
            'check_out'   => '2026-09-08 15:00:00',
            'source'      => 'manual',
            'company_id'  => $employee->company_id,
        ]);

        $summary = app(AttendanceOverviewService::class)->summaryFor(
            $employee->refresh(),
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-08'),
        );

        expect($summary['shortfall_minutes'])->toBe(60)
            ->and($summary['overtime_minutes'])->toBe(60);

        $component = Livewire::test(AttendanceOverview::class)
            ->call('showRegisterView', 'totals');

        $columns = collect($component->instance()->getTable()->getColumns());
        $shortfallCol = $columns->first(fn (object $c): bool => $c->getName() === 'totals.shortfall_minutes');
        $lateCol = $columns->first(fn (object $c): bool => $c->getName() === 'totals.late_minutes');

        expect($shortfallCol)->not->toBeNull()
            ->and($shortfallCol->isToggledHiddenByDefault())->toBeFalse()
            ->and($lateCol)->not->toBeNull()
            ->and($lateCol->isToggledHiddenByDefault())->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('displays scheduled_minutes and day-level remaining_minutes across multiple sessions in AttendancesTable without summarizers', function () {
    AttendancesTable::flushRuntimeCache();

    FilamentHelper::actingAs([
        'view_any_attendance_attendance',
    ])->forceFill(['is_active' => true])->saveQuietly();

    $calendar = Calendar::factory()->create([
        'timezone'   => 'UTC',
        'shift_type' => ShiftType::Fixed->value,
    ]);

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
        CalendarAttendance::create([
            'name'        => "{$day} morning",
            'day_of_week' => $day,
            'day_period'  => 'morning',
            'calendar_id' => $calendar->id,
            'hour_from'   => 8,
            'hour_to'     => 12,
        ]);
        CalendarAttendance::create([
            'name'        => "{$day} afternoon",
            'day_of_week' => $day,
            'day_period'  => 'afternoon',
            'calendar_id' => $calendar->id,
            'hour_from'   => 13,
            'hour_to'     => 17,
        ]);
    }

    $employee = Employee::factory()->create([
        'user_id'     => User::factory(),
        'is_active'   => true,
        'time_zone'   => 'UTC',
        'calendar_id' => $calendar->id,
    ]);

    // Two sessions on Monday 2026-09-07 totaling 6h out of 8h scheduled -> 2h (120m) remaining for the day
    $session1 = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-07',
        'check_in'    => '2026-09-07 08:00:00',
        'check_out'   => '2026-09-07 11:00:00',
        'source'      => 'manual',
        'company_id'  => $employee->company_id,
    ]);

    $session2 = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-07',
        'check_in'    => '2026-09-07 13:00:00',
        'check_out'   => '2026-09-07 16:00:00',
        'source'      => 'manual',
        'company_id'  => $employee->company_id,
    ]);

    $openSession = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-08',
        'check_in'    => '2026-09-08 08:00:00',
        'check_out'   => null,
        'source'      => 'manual',
        'company_id'  => $employee->company_id,
    ]);

    expect(AttendancesTable::scheduledMinutes($session1->load('employee.calendar.attendance')))->toBe(480)
        ->and(AttendancesTable::remainingMinutes($session1))->toBe(120)
        ->and(AttendancesTable::remainingMinutes($session2->load('employee.calendar.attendance')))->toBe(120)
        ->and(AttendancesTable::remainingMinutes($openSession->load('employee.calendar.attendance')))->toBeNull();

    $columns = collect(Livewire::test(ListAttendances::class)->instance()->getTable()->getColumns());

    foreach (['scheduled_minutes', 'worked_minutes', 'remaining_minutes'] as $colName) {
        $col = $columns->first(fn (object $c): bool => $c->getName() === $colName);
        expect($col)->not->toBeNull()
            ->and($col->getSummarizers())->toBe([]);
    }
});

it('exports grouped attendance records with per-employee summary rows and blank separator rows between employees', function () {
    AttendancesTable::flushRuntimeCache();

    FilamentHelper::actingAs([
        'view_any_attendance_attendance',
    ])->forceFill(['is_active' => true])->saveQuietly();

    $calendar = Calendar::factory()->create([
        'timezone'   => 'UTC',
        'shift_type' => ShiftType::Fixed->value,
    ]);

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
        CalendarAttendance::create([
            'name'        => "{$day} shift",
            'day_of_week' => $day,
            'calendar_id' => $calendar->id,
            'hour_from'   => 8,
            'hour_to'     => 16,
        ]);
    }

    $empAlpha = Employee::factory()->create([
        'name'        => 'Alpha Worker',
        'user_id'     => User::factory(),
        'is_active'   => true,
        'time_zone'   => 'UTC',
        'calendar_id' => $calendar->id,
    ]);

    $empBeta = Employee::factory()->create([
        'name'        => 'Beta Worker',
        'user_id'     => User::factory(),
        'is_active'   => true,
        'time_zone'   => 'UTC',
        'calendar_id' => $calendar->id,
    ]);

    // Alpha: two sessions on 2026-09-07 totaling 6h out of 8h scheduled -> 02:00 shortfall
    Attendance::create([
        'employee_id' => $empAlpha->id,
        'work_date'   => '2026-09-07',
        'check_in'    => '2026-09-07 08:00:00',
        'check_out'   => '2026-09-07 11:00:00',
        'source'      => 'manual',
        'company_id'  => $empAlpha->company_id,
    ]);
    Attendance::create([
        'employee_id' => $empAlpha->id,
        'work_date'   => '2026-09-07',
        'check_in'    => '2026-09-07 12:00:00',
        'check_out'   => '2026-09-07 15:00:00',
        'source'      => 'manual',
        'company_id'  => $empAlpha->company_id,
    ]);

    // Beta: one session on 2026-09-08 of 8h -> 00:00 shortfall
    Attendance::create([
        'employee_id' => $empBeta->id,
        'work_date'   => '2026-09-08',
        'check_in'    => '2026-09-08 08:00:00',
        'check_out'   => '2026-09-08 16:00:00',
        'source'      => 'manual',
        'company_id'  => $empBeta->company_id,
    ]);

    Livewire::test(ListAttendances::class)
        ->callTableAction('export', data: [
            'employee_id' => [$empAlpha->id, $empBeta->id],
            'date_from'   => '2026-09-07',
            'date_to'     => '2026-09-08',
        ]);

    $export = Export::query()->latest('id')->firstOrFail();

    expect($export->exporter)->toBe(AttendanceExporter::class)
        ->and($export->total_rows)->toBe(3)
        ->and($export->successful_rows)->toBe(3);

    $disk = $export->getFileDisk();
    $directory = $export->getFileDirectory();
    $dataFile = collect($disk->files($directory))
        ->first(fn (string $file): bool => str($file)->endsWith('.csv') && ! str($file)->endsWith('headers.csv'));

    expect($dataFile)->not->toBeNull();

    $rows = array_map(
        fn (string $line): array => str_getcsv($line),
        explode("\n", trim($disk->get($dataFile)))
    );

    // 2 sessions for Alpha + 1 summary for Alpha + 1 blank separator + 1 session for Beta + 1 summary for Beta = 6 rows
    expect($rows)->toHaveCount(6)
        ->and($rows[0][0])->toBe('Alpha Worker')
        ->and($rows[1][0])->toBe('Alpha Worker')
        ->and($rows[2][0])->toBe('Total — Alpha Worker')
        ->and($rows[2][1])->toBe('1 days')
        ->and($rows[2][4])->toBe('08:00')
        ->and($rows[2][5])->toBe('06:00')
        ->and($rows[2][6])->toBe('02:00')
        ->and(array_filter($rows[3]))->toBe([])
        ->and($rows[4][0])->toBe('Beta Worker')
        ->and($rows[5][0])->toBe('Total — Beta Worker')
        ->and($rows[5][4])->toBe('08:00')
        ->and($rows[5][5])->toBe('08:00')
        ->and($rows[5][6])->toBe('00:00');
});

it('treats missing checkout days under IncompleteAbsent as absent only with zero present weight and zero variance', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');

    try {
        $calendar = Calendar::factory()->create([
            'timezone'                => 'UTC',
            'shift_type'              => ShiftType::Fixed->value,
            'missing_checkout_policy' => MissingCheckoutPolicy::IncompleteAbsent->value,
        ]);

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
            CalendarAttendance::create([
                'name'        => "{$day} shift",
                'day_of_week' => $day,
                'day_period'  => 'morning',
                'calendar_id' => $calendar->id,
                'hour_from'   => 8,
                'hour_to'     => 16,
            ]);
        }

        $employee = Employee::factory()->create([
            'user_id'     => User::factory(),
            'is_active'   => true,
            'time_zone'   => 'UTC',
            'calendar_id' => $calendar->id,
        ]);

        // 2026-09-07 (Monday): missing check_out
        Attendance::create([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-07',
            'check_in'    => '2026-09-07 08:00:00',
            'check_out'   => null,
            'source'      => 'manual',
            'company_id'  => $employee->company_id,
        ]);

        $service = app(AttendanceOverviewService::class);
        $employees = Employee::whereKey($employee->id)->get();
        $from = Carbon::parse('2026-09-07');
        $to = Carbon::parse('2026-09-07');

        $entry = $service->daily($employees, $from, $to)->first();
        $summary = $service->summaryFor($employee->refresh(), $from, $to);

        expect($entry)->not->toBeNull()
            ->and((float) $entry['present_weight'])->toBe(0.0)
            ->and((float) $entry['absent_weight'])->toBe(1.0)
            ->and($entry['variance_minutes'])->toBe(0)
            ->and((float) $summary['present'])->toBe(0.0)
            ->and((float) $summary['absent'])->toBe(1.0)
            ->and($summary['variance_minutes'])->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('excludes incomplete days from scheduled_minutes in AttendanceExporter summaryRowForEmployee and shows missing check-out count', function () {
    AttendancesTable::flushRuntimeCache();

    $calendar = Calendar::factory()->create([
        'timezone'   => 'UTC',
        'shift_type' => ShiftType::Fixed->value,
    ]);

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
        CalendarAttendance::create([
            'name'        => "{$day} shift",
            'day_of_week' => $day,
            'calendar_id' => $calendar->id,
            'hour_from'   => 8,
            'hour_to'     => 16,
        ]);
    }

    $employee = Employee::factory()->create([
        'name'        => 'Sameer Worker',
        'user_id'     => User::factory(),
        'is_active'   => true,
        'time_zone'   => 'UTC',
        'calendar_id' => $calendar->id,
    ]);

    // Completed day: 2026-09-07 (6h worked out of 8h scheduled -> 02:00 shortfall)
    $completed = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-07',
        'check_in'    => '2026-09-07 08:00:00',
        'check_out'   => '2026-09-07 14:00:00',
        'source'      => 'manual',
        'company_id'  => $employee->company_id,
    ]);

    // Incomplete days: 2026-09-08 & 2026-09-09 (missing check_out)
    $incomplete1 = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-08',
        'check_in'    => '2026-09-08 08:00:00',
        'check_out'   => null,
        'source'      => 'manual',
        'company_id'  => $employee->company_id,
    ]);

    $incomplete2 = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-09',
        'check_in'    => '2026-09-09 08:00:00',
        'check_out'   => null,
        'source'      => 'manual',
        'company_id'  => $employee->company_id,
    ]);

    $records = Attendance::with(['employee.calendar.attendance'])
        ->whereKey([$completed->id, $incomplete1->id, $incomplete2->id])
        ->orderBy('work_date')
        ->get();

    $columnKeys = [
        'employee.name',
        'work_date',
        'check_in',
        'check_out',
        'scheduled_minutes',
        'worked_minutes',
        'remaining_minutes',
        'break_minutes',
        'is_estimated',
        'source',
    ];

    $exporter = new AttendanceExporter(new Export, array_combine($columnKeys, $columnKeys), []);
    $summaryRow = $exporter->summaryRowForEmployee($employee, $records, $columnKeys);

    expect($summaryRow[0])->toBe('Total — Sameer Worker')
        ->and($summaryRow[1])->toBe('3 days')
        ->and($summaryRow[3])->toBe('2 missing check-outs')
        ->and($summaryRow[4])->toBe('08:00')
        ->and($summaryRow[5])->toBe('06:00')
        ->and($summaryRow[6])->toBe('02:00');
});

it('treats employee currently in progress today as present on daily cell and excludes from summary absences or missing checkouts', function () {
    Carbon::setTestNow('2026-09-15 10:00:00');

    try {
        $calendar = Calendar::factory()->create([
            'timezone'   => 'UTC',
            'shift_type' => ShiftType::Fixed->value,
        ]);

        CalendarAttendance::create([
            'name'        => 'tuesday shift',
            'day_of_week' => 'tuesday',
            'calendar_id' => $calendar->id,
            'hour_from'   => 8,
            'hour_to'     => 16,
        ]);

        $employee = Employee::factory()->create([
            'user_id'     => User::factory(),
            'is_active'   => true,
            'time_zone'   => 'UTC',
            'calendar_id' => $calendar->id,
        ]);

        // Checked in at 08:00 today (2026-09-15), no check_out yet. Current time is 10:00 (shift ends at 16:00).
        Attendance::create([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-15',
            'check_in'    => '2026-09-15 08:00:00',
            'check_out'   => null,
            'source'      => 'manual',
            'company_id'  => $employee->company_id,
        ]);

        $service = app(AttendanceOverviewService::class);
        $employees = Employee::whereKey($employee->id)->get();
        $date = Carbon::parse('2026-09-15');

        $dailyEntry = $service->daily($employees, $date, $date)->first();
        expect($dailyEntry)->not->toBeNull()
            ->and($dailyEntry['status'])->toBe(AttendanceOverviewService::STATUS_PRESENT)
            ->and($dailyEntry['in_progress'])->toBeTrue()
            ->and((float) $dailyEntry['present_weight'])->toBe(0.0)
            ->and((float) $dailyEntry['absent_weight'])->toBe(0.0)
            ->and($dailyEntry['scheduled_minutes'])->toBe(480);

        $summary = $service->summaryFor($employee->refresh(), $date, $date);
        expect((float) $summary['present'])->toBe(0.0)
            ->and((float) $summary['absent'])->toBe(0.0)
            ->and($summary['workdays'])->toBe(0)
            ->and($summary['missing_checkouts'])->toBe(0)
            ->and($summary['scheduled_minutes'])->toBe(0);

        $register = $service->register($employees, $date, $date)->first();
        expect($register['cells']['2026-09-15'])->toBe(AttendanceOverviewService::STATUS_PRESENT)
            ->and($register['meta']['2026-09-15']['in_progress'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('zeros out scheduled_minutes in approved full-day leave and in official public holidays', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        $company = CompanyHelper::company();

        $calendar = Calendar::factory()->create([
            'timezone'   => 'UTC',
            'shift_type' => ShiftType::Fixed->value,
            'company_id' => $company->id,
        ]);

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
            CalendarAttendance::create([
                'name'        => "{$day} shift",
                'day_of_week' => $day,
                'day_period'  => 'morning',
                'calendar_id' => $calendar->id,
                'hour_from'   => 8,
                'hour_to'     => 16,
            ]);
        }

        $employee = Employee::factory()->create([
            'user_id'     => User::factory(),
            'is_active'   => true,
            'time_zone'   => 'UTC',
            'calendar_id' => $calendar->id,
            'company_id'  => $company->id,
        ]);

        // Official public holiday on Monday 2026-09-07
        CalendarLeave::create([
            'name'        => 'Official Holiday',
            'time_type'   => 'leave',
            'date_from'   => '2026-09-07 00:00:00',
            'date_to'     => '2026-09-07 23:59:59',
            'calendar_id' => $calendar->id,
            'company_id'  => $company->id,
        ]);

        // Approved full-day leave on Tuesday 2026-09-08
        Auth::login($employee->user);

        $leaveType = LeaveType::create([
            'name'                  => 'Annual Leave',
            'leave_validation_type' => LeaveValidationType::NO_VALIDATION,
            'company_id'            => $company->id,
        ]);

        Leave::create([
            'employee_id'       => $employee->id,
            'holiday_status_id' => $leaveType->id,
            'date_from'         => '2026-09-08 00:00:00',
            'date_to'           => '2026-09-08 23:59:59',
            'request_date_from' => '2026-09-08',
            'request_date_to'   => '2026-09-08',
            'state'             => State::VALIDATE_TWO,
            'company_id'        => $company->id,
        ]);

        CompanyHelper::actingAsCompanyUser($company);

        $service = app(AttendanceOverviewService::class);
        $employees = Employee::whereKey($employee->id)->get();

        $leaveEntry = $service->daily($employees, Carbon::parse('2026-09-08'), Carbon::parse('2026-09-08'))->first();
        expect($leaveEntry)->not->toBeNull()
            ->and($leaveEntry['status'])->toBe(AttendanceOverviewService::STATUS_ON_LEAVE)
            ->and($leaveEntry['scheduled_minutes'])->toBe(0)
            ->and($leaveEntry['variance_minutes'])->toBe(0);

        $register = $service->register($employees, Carbon::parse('2026-09-07'), Carbon::parse('2026-09-08'))->first();
        expect($register['cells']['2026-09-07'])->toBe(AttendanceOverviewService::STATUS_HOLIDAY)
            ->and($register['cells']['2026-09-08'])->toBe(AttendanceOverviewService::STATUS_ON_LEAVE);

        // Effective scheduled minutes check for AttendancesTable
        expect($service->effectiveScheduledMinutesForDay($employee, '2026-09-07'))->toBe(0)
            ->and($service->effectiveScheduledMinutesForDay($employee, '2026-09-08'))->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('matches shortfall_minutes and does not compute late or early leave on official public holidays', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    try {
        $calendar = Calendar::factory()->create([
            'timezone'   => 'UTC',
            'shift_type' => ShiftType::Fixed->value,
        ]);

        CalendarAttendance::create([
            'name'        => 'monday shift',
            'day_of_week' => 'monday',
            'day_period'  => 'morning',
            'calendar_id' => $calendar->id,
            'hour_from'   => 8,
            'hour_to'     => 16,
        ]);

        $employee = Employee::factory()->create([
            'user_id'     => User::factory(),
            'is_active'   => true,
            'time_zone'   => 'UTC',
            'calendar_id' => $calendar->id,
        ]);

        // Monday 2026-09-07 is an official holiday
        CalendarLeave::create([
            'name'        => 'National Day',
            'time_type'   => 'leave',
            'date_from'   => '2026-09-07 00:00:00',
            'date_to'     => '2026-09-07 23:59:59',
            'calendar_id' => $calendar->id,
            'company_id'  => $employee->company_id,
        ]);

        // Employee worked 09:30 to 14:00 (arrived late relative to shift, left early relative to shift)
        $att = Attendance::create([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-07',
            'check_in'    => '2026-09-07 09:30:00',
            'check_out'   => '2026-09-07 14:00:00',
            'source'      => 'manual',
            'company_id'  => $employee->company_id,
        ]);

        $service = app(AttendanceOverviewService::class);
        $employees = Employee::whereKey($employee->id)->get();
        $date = Carbon::parse('2026-09-07');

        $entry = $service->daily($employees, $date, $date)->first();

        expect($entry)->not->toBeNull()
            ->and($entry['status'])->toBe(AttendanceOverviewService::STATUS_HOLIDAY_PRESENT)
            ->and($entry['leave_label'])->toBe('National Day')
            ->and($entry['holiday_present'])->toBeTrue()
            ->and((float) $entry['present_weight'])->toBe(0.0)
            ->and($entry['late_minutes'])->toBe(0)
            ->and($entry['early_leave_minutes'])->toBe(0)
            ->and($entry['shortfall_minutes'])->toBe(0)
            ->and($entry['scheduled_minutes'])->toBe(0);

        $summary = $service->summaryFor($employee->refresh(), $date, $date);
        expect($summary['holidays'])->toBe(1)
            ->and((float) $summary['present'])->toBe(0.0)
            ->and((float) $summary['absent'])->toBe(0.0)
            ->and($summary['workdays'])->toBe(0)
            ->and($summary['shortfall_minutes'])->toBe(0);

        AttendancesTable::flushRuntimeCache();
        expect(AttendancesTable::scheduledMinutes($att))->toBe(0)
            ->and(AttendancesTable::remainingMinutes($att))->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('optimizes effectiveScheduledMinutesForDay caching across the month and unifies inspect day evaluation grid for mobile', function () {
    Carbon::setTestNow('2026-09-15 10:00:00');

    try {
        $calendar = Calendar::factory()->create([
            'timezone'   => 'UTC',
            'shift_type' => ShiftType::Fixed->value,
        ]);

        CalendarAttendance::create([
            'name'        => 'weekday shift',
            'day_of_week' => 'tuesday',
            'calendar_id' => $calendar->id,
            'hour_from'   => 8,
            'hour_to'     => 16,
        ]);

        $employee = Employee::factory()->create([
            'user_id'     => User::factory(),
            'is_active'   => true,
            'time_zone'   => 'UTC',
            'calendar_id' => $calendar->id,
        ]);

        Attendance::create([
            'employee_id' => $employee->id,
            'work_date'   => '2026-09-15',
            'check_in'    => '2026-09-15 08:00:00',
            'check_out'   => '2026-09-15 14:00:00',
            'source'      => 'manual',
            'company_id'  => $employee->company_id,
        ]);

        $service = app(AttendanceOverviewService::class);
        $service->flushEffectiveScheduledCache();

        // 1. Monthly caching check
        $scheduled15 = $service->effectiveScheduledMinutesForDay($employee, '2026-09-15');
        expect($scheduled15)->toBe(480);

        // Access private effectiveScheduledCache property via reflection to verify whole month was loaded
        $reflector = new ReflectionClass($service);
        $cacheProp = $reflector->getProperty('effectiveScheduledCache');
        $cacheProp->setAccessible(true);
        $cached = $cacheProp->getValue($service);

        expect(array_key_exists($employee->getKey().'|2026-09-01', $cached))->toBeTrue()
            ->and(array_key_exists($employee->getKey().'|2026-09-15', $cached))->toBeTrue()
            ->and(array_key_exists($employee->getKey().'|2026-09-29', $cached))->toBeTrue()
            ->and($service->effectiveScheduledMinutesForDay($employee, '2026-09-30'))->toBe(0);

        // 2. inspectDayCellAction check
        $page = new AttendanceOverview;
        $action = $page->inspectDayCellAction();
        expect($action->getModalDescription())->toBeNull();

        $schema = (new ReflectionClass($page))->getMethod('buildInspectDaySchema')->invoke($page, [
            'employee_id' => $employee->id,
            'date'        => '2026-09-15',
        ]);

        $evalSection = $schema[0];
        $evalComponents = $evalSection->getDefaultChildComponents();
        expect($evalComponents)->toHaveCount(1)
            ->and($evalComponents[0])->toBeInstanceOf(Grid::class)
            ->and($evalComponents[0]->getColumns())->toBe(['default' => 2, 'sm' => 3]);

        $gridComponents = $evalComponents[0]->getDefaultChildComponents();
        $componentsByName = collect($gridComponents)
            ->filter(fn (object $c): bool => method_exists($c, 'getName'))
            ->keyBy(fn (object $c): string => $c->getName());

        expect($componentsByName->has('shift'))->toBeTrue()
            ->and($componentsByName->get('shift')->isBadge())->toBeTrue()
            ->and($componentsByName->get('shift')->getExtraEntryWrapperAttributes()['class'] ?? '')->toContain('hidden sm:block')
            ->and($componentsByName->has('worked_hours'))->toBeTrue()
            ->and($componentsByName->get('worked_hours')->isBadge())->toBeTrue()
            ->and((string) $componentsByName->get('worked_hours')->getState())->toStartWith("\u{200E}")
            ->and($componentsByName->has('session_times'))->toBeTrue()
            ->and($componentsByName->get('session_times')->isBadge())->toBeTrue()
            ->and((string) $componentsByName->get('session_times')->getState())->toStartWith("\u{200E}")
            ->and($componentsByName->has('shortfall'))->toBeTrue()
            ->and($componentsByName->get('shortfall')->isVisible())->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});
