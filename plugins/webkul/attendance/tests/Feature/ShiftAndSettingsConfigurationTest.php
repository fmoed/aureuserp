<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Webkul\Attendance\Enums\EarlyArrivalPolicy;
use Webkul\Attendance\Enums\HolidayPolicy;
use Webkul\Attendance\Enums\MissingCheckoutPolicy;
use Webkul\Attendance\Enums\PairingMode;
use Webkul\Attendance\Enums\ShiftType;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\EditCalendar;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\RelationManagers\EmployeesRelationManager;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\RelationManagers\PublicHolidaysRelationManager;
use Webkul\Attendance\Filament\Clusters\Settings\Pages\ManageAttendance;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Filament\Resources\CalendarResource\RelationManagers\CalendarAttendance;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarLeave;
use Webkul\TimeOff\Filament\Clusters\Configurations\Resources\PublicHolidayResource\Pages\ListPublicHolidays;
use Webkul\TimeOff\Models\LeaveMandatoryDay;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');
    TestBootstrapHelper::ensurePluginInstalled('time-off');
});

function configurationCalendarUser(array $extra = []): void
{
    FilamentHelper::actingAs(array_merge([
        'view_any_support_calendar',
        'view_support_calendar',
        'update_support_calendar',
        'update_attendance_calendar',
        'create_time_off_public::holiday',
        'view_any_time_off_public::holiday',
    ], $extra));
}

it('saves every shift policy from the calendar edit page and global policies from manage attendance', function () {
    configurationCalendarUser(['page_attendance_manage_attendance']);

    $calendar = Calendar::factory()->create([
        'name'      => 'Policy Editor',
        'timezone'  => 'UTC',
        'is_active' => true,
    ]);

    Livewire::test(EditCalendar::class, ['record' => $calendar->getRouteKey()])
        ->fillForm([
            'name'                           => 'Policy Editor',
            'timezone'                       => 'UTC',
            'shift_type'                     => ShiftType::Split->value,
            'check_in_window_before_minutes' => 90,
            'check_out_window_after_minutes' => 180,
            'late_grace_minutes'             => 20,
            'early_leave_grace_minutes'      => 5,
            'missing_checkout_policy'        => MissingCheckoutPolicy::IncompleteHalfDay->value,
            'overtime_enabled'               => false,
            'min_overtime_minutes'           => 45,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $calendar->refresh();

    expect($calendar->shift_type)->toBe(ShiftType::Split)
        ->and($calendar->pairing_mode)->toBe(PairingMode::EveryInOutPair)
        ->and($calendar->check_in_window_before_minutes)->toBe(90)
        ->and($calendar->check_out_window_after_minutes)->toBe(180)
        ->and($calendar->late_grace_minutes)->toBe(20)
        ->and($calendar->early_leave_grace_minutes)->toBe(5)
        ->and($calendar->missing_checkout_policy)->toBe(MissingCheckoutPolicy::IncompleteHalfDay)
        ->and($calendar->overtime_enabled)->toBeFalse();

    Livewire::test(ManageAttendance::class)
        ->fillForm([
            'half_day_threshold_percent' => 45,
            'holiday_policy'             => HolidayPolicy::WorkRequired->value,
            'early_arrival_policy'       => EarlyArrivalPolicy::OffsetEarlyDeparture->value,
            'max_early_offset_minutes'   => 45,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app(AttendanceSettings::class)->refresh();

    expect(AttendanceSettings::halfDayThresholdPercent())->toBe(45)
        ->and(AttendanceSettings::holidayPolicy())->toBe(HolidayPolicy::WorkRequired)
        ->and(AttendanceSettings::earlyArrivalPolicy())->toBe(EarlyArrivalPolicy::OffsetEarlyDeparture)
        ->and(AttendanceSettings::maxEarlyOffsetMinutes())->toBe(45);
});

it('rejects a shift whose acceptance windows exceed 24 hours', function () {
    configurationCalendarUser();

    $calendar = Calendar::factory()->create([
        'name'      => 'Overlap Shift',
        'timezone'  => 'UTC',
        'is_active' => true,
    ]);

    Livewire::test(EditCalendar::class, ['record' => $calendar->getRouteKey()])
        ->fillForm([
            'name'                           => 'Overlap Shift',
            'timezone'                       => 'UTC',
            'hours_per_day'                  => 24,
            'check_in_window_before_minutes' => 120,
            'check_out_window_after_minutes' => 240,
        ])
        ->call('save')
        ->assertHasFormErrors(['check_out_window_after_minutes']);
});

it('manages shift public holidays through the relation manager', function () {
    configurationCalendarUser();

    $calendar = Calendar::factory()->create([
        'name'      => 'Holiday Shift',
        'timezone'  => 'UTC',
        'is_active' => true,
    ]);

    $companyHoliday = CalendarLeave::create([
        'name'      => 'Company Day',
        'time_type' => 'leave',
        'date_from' => '2026-12-01 00:00:00',
        'date_to'   => '2026-12-01 23:59:59',
    ]);

    Livewire::test(PublicHolidaysRelationManager::class, [
        'ownerRecord' => $calendar,
        'pageClass'   => EditCalendar::class,
    ])
        ->callTableAction('assignExistingHoliday', null, ['holiday_id' => $companyHoliday->id])
        ->assertHasNoActionErrors();

    expect(CalendarLeave::where('calendar_id', $calendar->id)->where('name', 'Company Day')->count())->toBe(1);

    Livewire::test(PublicHolidaysRelationManager::class, [
        'ownerRecord' => $calendar,
        'pageClass'   => EditCalendar::class,
    ])
        ->callTableAction('create', null, [
            'name'      => 'Shift Day',
            'date_from' => '2026-12-05',
            'date_to'   => '2026-12-05',
        ])
        ->assertHasNoActionErrors();

    expect(CalendarLeave::where('calendar_id', $calendar->id)->where('name', 'Shift Day')->count())->toBe(1)
        ->and(CalendarLeave::where('calendar_id', $calendar->id)->where('name', 'Shift Day')->first()->time_type)->toBe('leave');
});

it('allows entering a public holiday in the past', function () {
    configurationCalendarUser();

    Livewire::test(ListPublicHolidays::class)
        ->callAction('create', [
            'name'      => 'Retroactive Holiday',
            'date_from' => '2020-01-01',
            'date_to'   => '2020-01-02',
        ])
        ->assertHasNoActionErrors();

    expect(CalendarLeave::where('name', 'Retroactive Holiday')->count())->toBe(1);
});

it('keeps the working periods action available on flexible shifts', function () {
    configurationCalendarUser();

    $calendar = Calendar::factory()->create([
        'name'           => 'Flexible Shift',
        'timezone'       => 'UTC',
        'is_active'      => true,
        'shift_type'     => ShiftType::Flexible,
        'flexible_hours' => true,
    ]);

    Livewire::test(CalendarAttendance::class, [
        'ownerRecord' => $calendar,
        'pageClass'   => EditCalendar::class,
    ])->assertTableActionVisible('create');
});

it('binds a mandatory day to a shift', function () {
    configurationCalendarUser();

    $calendar = Calendar::factory()->create(['name' => 'Mandatory Shift', 'timezone' => 'UTC']);

    $day = LeaveMandatoryDay::create([
        'name'        => 'Inventory Day',
        'start_date'  => '2026-11-15 00:00:00',
        'end_date'    => '2026-11-15 23:59:59',
        'calendar_id' => $calendar->id,
    ]);

    expect($day->fresh()->calendar_id)->toBe($calendar->id)
        ->and($day->calendar->name)->toBe('Mandatory Shift');
});

it('blocks manual edits and deletes of locked attendance rows', function () {
    $user = FilamentHelper::actingAs([
        'view_any_attendance_attendance',
        'update_attendance_attendance',
        'delete_attendance_attendance',
    ]);

    $employee = Employee::factory()->create();

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => now()->toDateString(),
        'check_in'    => now()->startOfDay()->addHours(8)->toDateTimeString(),
        'check_out'   => now()->startOfDay()->addHours(17)->toDateTimeString(),
        'source'      => 'manual',
    ]);

    expect($user->can('update', $attendance))->toBeTrue()
        ->and($user->can('delete', $attendance))->toBeTrue();

    DB::table('settings')
        ->where('group', 'attendance')
        ->where('name', 'attendance_lock_date')
        ->update(['payload' => json_encode(now()->toDateString())]);

    app(AttendanceSettings::class)->refresh();

    expect($user->can('update', $attendance->refresh()))->toBeFalse()
        ->and($user->can('delete', $attendance))->toBeFalse();
});

it('validates split shift period totals against hours per day and syncs calendar attendances on save', function () {
    configurationCalendarUser();

    $calendar = Calendar::factory()->create([
        'name'          => 'Split Validation Shift',
        'timezone'      => 'UTC',
        'hours_per_day' => 8,
        'is_active'     => true,
    ]);

    $component = Livewire::test(EditCalendar::class, ['record' => $calendar->getRouteKey()])
        ->fillForm([
            'name'          => 'Split Validation Shift',
            'timezone'      => 'UTC',
            'shift_type'    => ShiftType::Split->value,
            'work_days'     => ['monday', 'tuesday'],
            'shift_periods' => [
                ['day_period' => 'morning', 'hour_from' => '08:00', 'hour_to' => '11:00'],
                ['day_period' => 'afternoon', 'hour_from' => '16:00', 'hour_to' => '20:00'],
            ],
        ]);

    // Live calculation derives 7h from the periods; setting an explicitly conflicting hours_per_day triggers validation
    expect((float) $component->get('data.hours_per_day'))->toBe(7.0);

    $component
        ->set('data.hours_per_day', 8)
        ->call('save')
        ->assertHasFormErrors(['hours_per_day']);

    Livewire::test(EditCalendar::class, ['record' => $calendar->getRouteKey()])
        ->fillForm([
            'name'          => 'Split Validation Shift',
            'timezone'      => 'UTC',
            'shift_type'    => ShiftType::Split->value,
            'hours_per_day' => 8,
            'work_days'     => ['monday', 'tuesday'],
            'shift_periods' => [
                ['day_period' => 'morning', 'hour_from' => '08:00', 'hour_to' => '12:00'],
                ['day_period' => 'afternoon', 'hour_from' => '16:00', 'hour_to' => '20:00'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($calendar->fresh()->attendance()->count())->toBe(4);
});

it('does not dirty the calendar model with employees_count when rendering relation manager badges on edit', function () {
    configurationCalendarUser();

    $calendar = Calendar::factory()->create([
        'name'          => 'Clean Badge Shift',
        'timezone'      => 'UTC',
        'hours_per_day' => 8,
        'is_active'     => true,
    ]);

    $component = Livewire::test(EditCalendar::class, ['record' => $calendar->getRouteKey()])
        ->set('activeRelationManager', '1');

    EmployeesRelationManager::getBadge(
        $component->instance()->getRecord(),
        EditCalendar::class,
    );

    $component
        ->fillForm([
            'name'                     => 'Clean Badge Shift Updated',
            'timezone'                 => 'UTC',
            'full_time_required_hours' => 30,
            'overtime_enabled'         => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($calendar->fresh()->name)->toBe('Clean Badge Shift Updated')
        ->and($calendar->fresh()->overtime_enabled)->toBeFalse();
});
