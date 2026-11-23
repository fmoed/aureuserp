<?php

namespace Webkul\RemoteAttendance\Settings;

use Spatie\LaravelSettings\Settings;
use Throwable;
use Webkul\Employee\Enums\WorkLocation as WorkLocationEnum;

class RemoteAttendanceSettings extends Settings
{
    public const DEFAULT_REMINDER_TIME = '09:00';

    public bool $reminder_enabled = true;

    public string $reminder_time = self::DEFAULT_REMINDER_TIME;

    public bool $require_checkout = false;

    public bool $only_home_location = true;

    public array $allowed_location_types = [WorkLocationEnum::Home->value];

    public array $allowed_work_location_ids = [];

    public static function group(): string
    {
        return 'remote_attendance';
    }

    public static function reminderEnabled(): bool
    {
        return (bool) static::value('reminder_enabled', true);
    }

    public static function reminderTime(): string
    {
        $time = (string) static::value('reminder_time', self::DEFAULT_REMINDER_TIME);

        return preg_match('/^\d{2}:\d{2}$/', $time) ? $time : self::DEFAULT_REMINDER_TIME;
    }

    public static function requireCheckout(): bool
    {
        return (bool) static::value('require_checkout', false);
    }

    public static function onlyHomeLocation(): bool
    {
        return (bool) static::value('only_home_location', true);
    }

    /**
     * @return list<string>
     */
    public static function allowedLocationTypes(): array
    {
        $types = static::value('allowed_location_types', [WorkLocationEnum::Home->value]);

        if (! is_array($types)) {
            return [WorkLocationEnum::Home->value];
        }

        return array_values(array_filter(array_map('strval', $types), fn (string $v): bool => $v !== ''));
    }

    /**
     * @return list<int>
     */
    public static function allowedWorkLocationIds(): array
    {
        $ids = static::value('allowed_work_location_ids', []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0));
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
