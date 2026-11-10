<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Webkul\Attendance\Enums\ShiftType;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\EditCalendar as CalendarEditPage;
use Webkul\Attendance\Filament\Pages\AttendanceOverview;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Pages\CreateAttendance;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Pages\EditAttendance;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;

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
