<?php

namespace Webkul\BiometricAttendance\Settings;

use Spatie\LaravelSettings\Settings;

class BiometricSyncSettings extends Settings
{
    /**
     * PHP default doubles as the fallback when the settings row was never
     * migrated (fresh plugin installs): spatie loads it instead of
     * throwing MissingSettings, so the page always renders.
     */
    public string $sync_cadence = 'hourly';

    public static function group(): string
    {
        return 'biometric_sync';
    }
}
