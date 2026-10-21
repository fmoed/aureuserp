<?php

namespace Webkul\Attendance\Settings;

use Spatie\LaravelSettings\Settings;
use Throwable;

class AttendanceSettings extends Settings
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_ENROLLED_OR_ACTIVE = 'enrolled_or_active';

    public const SCOPE_ENROLLED = 'enrolled';

    public const SCOPE_RECENT_ACTIVITY = 'recent_activity';

    /**
     * PHP default doubles as the fallback when the settings row was never
     * migrated (fresh plugin installs or existing test databases): spatie
     * loads it instead of throwing MissingSettings, so the page always renders.
     */
    public string $employee_scope = self::SCOPE_ALL;

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
}
