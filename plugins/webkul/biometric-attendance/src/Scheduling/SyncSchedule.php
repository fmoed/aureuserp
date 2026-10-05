<?php

namespace Webkul\BiometricAttendance\Scheduling;

use Illuminate\Console\Scheduling\Event;

/**
 * Single source of truth for the sync cadence. Today it reads an optional
 * config key (default: hourly); the future Attendance settings page binds
 * its interval dropdown to ::options() and stores the choice where the
 * config reads it from — no schedule call sites change.
 */
final class SyncSchedule
{
    public const DEFAULT_CADENCE = 'hourly';

    /**
     * @return list<string>
     */
    public static function options(): array
    {
        return ['fifteen-minutes', 'thirty-minutes', 'hourly', 'daily'];
    }

    public static function cronExpression(?string $cadence = null): string
    {
        return match ($cadence ?? config('biometric-attendance.sync_cadence', self::DEFAULT_CADENCE)) {
            'fifteen-minutes' => '*/15 * * * *',
            'thirty-minutes'  => '*/30 * * * *',
            'daily'           => '0 0 * * *',
            default           => '0 * * * *',
        };
    }

    public static function apply(Event $event, ?string $cadence = null): Event
    {
        return $event->cron(self::cronExpression($cadence));
    }
}
