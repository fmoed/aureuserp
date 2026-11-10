<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

use Illuminate\Support\Facades\Schema;
use Webkul\Attendance\Enums\BreakPolicy;
use Webkul\Attendance\Enums\EarlyArrivalPolicy;
use Webkul\Attendance\Enums\HolidayPolicy;
use Webkul\Attendance\Enums\MandatoryDaysPolicy;
use Webkul\Attendance\Enums\MissingCheckoutPolicy;
use Webkul\Attendance\Enums\PairingMode;
use Webkul\Attendance\Enums\RoundingMode;
use Webkul\Attendance\Enums\ShiftType;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarLeave;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');
    TestBootstrapHelper::ensurePluginInstalled('time-off');
});

it('exposes the enterprise shift policy columns with enum casts', function () {
    $calendar = Calendar::factory()->create([
        'name'                    => 'Policy Shift',
        'timezone'                => 'UTC',
        'shift_type'              => ShiftType::Flexible,
        'pairing_mode'            => PairingMode::EveryInOutPair,
        'early_arrival_policy'    => EarlyArrivalPolicy::OffsetEarlyDeparture,
        'break_policy'            => BreakPolicy::PunchBased,
        'missing_checkout_policy' => MissingCheckoutPolicy::AutoCreditScheduled,
        'holiday_policy'          => HolidayPolicy::WorkRequired,
    ]);

    $fresh = $calendar->fresh();

    expect($fresh->shift_type)->toBe(ShiftType::Flexible)
        ->and($fresh->pairing_mode)->toBe(PairingMode::EveryInOutPair)
        ->and($fresh->check_in_window_before_minutes)->toBe(120)
        ->and($fresh->check_out_window_after_minutes)->toBe(240)
        ->and($fresh->late_grace_minutes)->toBe(15)
        ->and($fresh->early_leave_grace_minutes)->toBe(10)
        ->and($fresh->early_arrival_policy)->toBe(EarlyArrivalPolicy::OffsetEarlyDeparture)
        ->and($fresh->max_early_offset_minutes)->toBe(60)
        ->and($fresh->break_policy)->toBe(BreakPolicy::PunchBased)
        ->and($fresh->break_duration_minutes)->toBe(60)
        ->and($fresh->break_trigger_minutes)->toBe(300)
        ->and($fresh->missing_checkout_policy)->toBe(MissingCheckoutPolicy::AutoCreditScheduled)
        ->and($fresh->holiday_policy)->toBe(HolidayPolicy::WorkRequired)
        ->and($fresh->half_day_threshold_percent)->toBe(50)
        ->and($fresh->absent_threshold_percent)->toBe(25)
        ->and($fresh->overtime_enabled)->toBeTrue()
        ->and($fresh->min_overtime_minutes)->toBe(30);
});

it('stores break minutes and the estimated flag on attendance rows', function () {
    $calendar = Calendar::factory()->create(['timezone' => 'UTC']);

    $attendance = Attendance::factory()->create([
        'work_date'     => '2026-10-05',
        'check_in'      => '2026-10-05 08:00:00',
        'check_out'     => '2026-10-05 17:00:00',
        'break_minutes' => 45,
        'is_estimated'  => true,
        'source'        => 'manual',
    ]);

    expect($attendance->fresh()->break_minutes)->toBe(45)
        ->and($attendance->fresh()->is_estimated)->toBeTrue()
        ->and($calendar->exists)->toBeTrue();
});

it('resolves public holidays bound to a shift through the calendar model', function () {
    $calendar = Calendar::factory()->create(['name' => 'Holiday Shift', 'timezone' => 'UTC']);

    CalendarLeave::create([
        'name'        => 'Shift Holiday',
        'time_type'   => 'leave',
        'date_from'   => '2026-10-06 00:00:00',
        'date_to'     => '2026-10-06 23:59:59',
        'calendar_id' => $calendar->id,
    ]);

    CalendarLeave::create([
        'name'      => 'Company Holiday',
        'time_type' => 'leave',
        'date_from' => '2026-10-07 00:00:00',
        'date_to'   => '2026-10-07 23:59:59',
    ]);

    expect($calendar->publicHolidays()->pluck('name')->all())->toBe(['Shift Holiday']);
});

it('exposes the enterprise attendance settings defaults', function () {
    $settings = app(AttendanceSettings::class);

    expect($settings->default_missing_checkout_policy)->toBe(MissingCheckoutPolicy::IncompleteAbsent->value)
        ->and($settings->mandatory_days_policy)->toBe(MandatoryDaysPolicy::TreatAsHoliday->value)
        ->and($settings->count_unpaid_leave_as_absent)->toBeFalse()
        ->and($settings->rounding_mode)->toBe(RoundingMode::None->value)
        ->and($settings->attendance_lock_date)->toBeNull()
        ->and(AttendanceSettings::lockDate())->toBeNull();
});

it('adds an optional calendar binding to mandatory days', function () {
    expect(Schema::hasColumn('time_off_leave_mandatory_days', 'calendar_id'))->toBeTrue();
});

it('offers stable option arrays for every policy enum', function () {
    expect(ShiftType::options())->toHaveCount(4)
        ->and(PairingMode::options())->toHaveCount(3)
        ->and(EarlyArrivalPolicy::options())->toHaveCount(3)
        ->and(BreakPolicy::options())->toHaveCount(3)
        ->and(MissingCheckoutPolicy::options())->toHaveCount(3)
        ->and(HolidayPolicy::options())->toHaveCount(3)
        ->and(MandatoryDaysPolicy::options())->toHaveCount(3)
        ->and(RoundingMode::options())->toHaveCount(3)
        ->and(ShiftType::Fixed->getLabel())->not->toBeEmpty()
        ->and(ShiftType::Fixed->getColor())->not->toBeEmpty();
});
