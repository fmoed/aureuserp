<?php

namespace Webkul\BiometricAttendance\Scheduling;

use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Throwable;
use Webkul\BiometricAttendance\Settings\BiometricSyncSettings;

/**
 * Single source of truth for the sync cadence. The effective value comes
 * from the BiometricSyncSettings page (DB-backed, default: hourly); the
 * config key stays as a fallback so boot never breaks on a fresh install
 * before the settings migration has run.
 *
 * Scheduling is deliberately tick-based, not boot-based: events are
 * registered on a fixed fifteen-minute grid and gated per tick with
 * isDue(), so no settings read happens at boot time and cadence changes
 * apply on the next tick without rebooting the scheduler.
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

    public static function storedCadence(): string
    {
        try {
            $cadence = app(BiometricSyncSettings::class)->sync_cadence;
        } catch (Throwable) {
            $cadence = null;
        }

        if (! in_array($cadence, self::options(), true)) {
            $cadence = config('biometric-attendance.sync_cadence', self::DEFAULT_CADENCE);
        }

        return in_array($cadence, self::options(), true) ? $cadence : self::DEFAULT_CADENCE;
    }

    public static function cronExpression(?string $cadence = null): string
    {
        return match ($cadence ?? self::storedCadence()) {
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

    /**
     * Tick-time gate: true when the stored cadence is due at the given
     * moment. Used with ->skip() so the schedule itself stays static.
     */
    public static function isDue(?string $cadence = null, DateTimeInterface|string $now = 'now'): bool
    {
        try {
            return CronExpression::factory(self::cronExpression($cadence))->isDue($now);
        } catch (Throwable) {
            return false;
        }
    }
}
