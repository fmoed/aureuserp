<?php

namespace Webkul\Attendance\Settings;

use Illuminate\Support\Carbon;
use Spatie\LaravelSettings\Settings;
use Throwable;
use Webkul\Attendance\Enums\EarlyArrivalPolicy;
use Webkul\Attendance\Enums\HolidayPolicy;
use Webkul\Attendance\Enums\MandatoryDaysPolicy;
use Webkul\Attendance\Enums\MissingCheckoutPolicy;
use Webkul\Attendance\Enums\RoundingMode;

class AttendanceSettings extends Settings
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_ENROLLED_OR_ACTIVE = 'enrolled_or_active';

    public const SCOPE_ENROLLED = 'enrolled';

    public const SCOPE_RECENT_ACTIVITY = 'recent_activity';

    public const DEFAULT_DUPLICATE_PUNCH_SECONDS = 120;

    public const DEFAULT_MAX_SHIFT_HOURS = 16;

    /**
     * PHP default doubles as the fallback when the settings row was never
     * migrated (fresh plugin installs or existing test databases): spatie
     * loads it instead of throwing MissingSettings, so the page always renders.
     */
    public string $employee_scope = self::SCOPE_ALL;

    public bool $count_pending_leave_as_absent = true;

    public int $duplicate_punch_window_seconds = self::DEFAULT_DUPLICATE_PUNCH_SECONDS;

    public int $max_shift_hours = self::DEFAULT_MAX_SHIFT_HOURS;

    public string $default_missing_checkout_policy = 'incomplete_absent';

    public int $half_day_threshold_percent = 50;

    public string $early_arrival_policy = 'clamp_to_schedule';

    public int $max_early_offset_minutes = 60;

    public string $holiday_policy = 'excuse_all_applicable';

    public string $mandatory_days_policy = 'treat_as_holiday';

    public bool $count_unpaid_leave_as_absent = false;

    public string $rounding_mode = 'none';

    public ?string $attendance_lock_date = null;

    public static function group(): string
    {
        return 'attendance';
    }

    /**
     * @return list<string>
     */
    public static function options(): array
    {
        return [
            self::SCOPE_ALL,
            self::SCOPE_ENROLLED_OR_ACTIVE,
            self::SCOPE_ENROLLED,
            self::SCOPE_RECENT_ACTIVITY,
        ];
    }

    public static function storedScope(): string
    {
        try {
            $scope = app(self::class)->employee_scope;
        } catch (Throwable) {
            $scope = null;
        }

        return in_array($scope, self::options(), true) ? $scope : self::SCOPE_ALL;
    }

    public static function countPendingLeaveAsAbsent(): bool
    {
        return (bool) static::value('count_pending_leave_as_absent', true);
    }

    public static function duplicatePunchWindowSeconds(): int
    {
        return max(0, (int) static::value('duplicate_punch_window_seconds', self::DEFAULT_DUPLICATE_PUNCH_SECONDS));
    }

    public static function maxShiftHours(): int
    {
        return max(1, (int) static::value('max_shift_hours', self::DEFAULT_MAX_SHIFT_HOURS));
    }

    public static function defaultMissingCheckoutPolicy(): MissingCheckoutPolicy
    {
        $policy = static::value('default_missing_checkout_policy', MissingCheckoutPolicy::IncompleteAbsent->value);

        return $policy instanceof MissingCheckoutPolicy
            ? $policy
            : (MissingCheckoutPolicy::tryFrom((string) $policy) ?? MissingCheckoutPolicy::IncompleteAbsent);
    }

    public static function halfDayThresholdPercent(): int
    {
        return max(0, min(100, (int) static::value('half_day_threshold_percent', 50)));
    }

    public static function earlyArrivalPolicy(): EarlyArrivalPolicy
    {
        $policy = static::value('early_arrival_policy', EarlyArrivalPolicy::ClampToSchedule->value);

        return $policy instanceof EarlyArrivalPolicy
            ? $policy
            : (EarlyArrivalPolicy::tryFrom((string) $policy) ?? EarlyArrivalPolicy::ClampToSchedule);
    }

    public static function maxEarlyOffsetMinutes(): int
    {
        return max(0, (int) static::value('max_early_offset_minutes', 60));
    }

    public static function holidayPolicy(): HolidayPolicy
    {
        $policy = static::value('holiday_policy', HolidayPolicy::ExcuseAllApplicable->value);

        return $policy instanceof HolidayPolicy
            ? $policy
            : (HolidayPolicy::tryFrom((string) $policy) ?? HolidayPolicy::ExcuseAllApplicable);
    }

    public static function mandatoryDaysPolicy(): MandatoryDaysPolicy
    {
        $policy = static::value('mandatory_days_policy', MandatoryDaysPolicy::TreatAsHoliday->value);

        return $policy instanceof MandatoryDaysPolicy
            ? $policy
            : (MandatoryDaysPolicy::tryFrom((string) $policy) ?? MandatoryDaysPolicy::TreatAsHoliday);
    }

    public static function countUnpaidLeaveAsAbsent(): bool
    {
        return (bool) static::value('count_unpaid_leave_as_absent', false);
    }

    public static function roundingMode(): RoundingMode
    {
        $mode = static::value('rounding_mode', RoundingMode::None->value);

        return $mode instanceof RoundingMode
            ? $mode
            : (RoundingMode::tryFrom((string) $mode) ?? RoundingMode::None);
    }

    public static function lockDate(): ?Carbon
    {
        $value = static::value('attendance_lock_date', null);

        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    public static function isLockedDate(string|Carbon $workDate): bool
    {
        $lockDate = static::lockDate();

        if ($lockDate === null) {
            return false;
        }

        return Carbon::parse($workDate)->startOfDay()->lte($lockDate);
    }

    private static function value(string $property, mixed $default): mixed
    {
        try {
            $value = app(self::class)->{$property};
        } catch (Throwable) {
            return $default;
        }

        return $value ?? $default;
    }
}
