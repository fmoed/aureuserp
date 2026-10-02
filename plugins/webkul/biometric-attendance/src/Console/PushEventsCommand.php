<?php

namespace Webkul\BiometricAttendance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Events\EventGateway;
use Webkul\BiometricAttendance\Models\Punch;

class PushEventsCommand extends Command
{
    protected $signature = 'biometric:push-events';

    protected $description = 'Push matched biometric punches into attendance events (rerun-safe, no pairing here)';

    public function handle(): int
    {
        if (! class_exists(EventGateway::class)) {
            $this->error('Attendance plugin is not installed. Skipping push.');

            return self::FAILURE;
        }

        $punches = Punch::query()
            ->whereNotNull('employee_id')
            ->whereNotNull('punch_time')
            ->whereNull('pushed_at')
            ->with('device')
            ->orderBy('punch_time')
            ->orderBy('id')
            ->get();

        $pushed = 0;

        foreach ($punches as $punch) {
            $deviceTimezone = $punch->device?->timezone ?: config('app.timezone');
            $rawPunchTime = $punch->getRawOriginal('punch_time');

            $punchedAtUtc = Carbon::parse($rawPunchTime, $deviceTimezone)->setTimezone('UTC')->format('Y-m-d H:i:s');

            EventGateway::push(
                $punch->employee_id,
                $punchedAtUtc,
                self::directionFor((int) $punch->raw_state),
                "biometric-attendance:{$punch->device_id}",
                "punch:{$punch->getKey()}",
                $punch->device?->name,
            );

            $punch->update(['pushed_at' => now()]);

            $pushed++;
        }

        $this->info("Pushed {$pushed} punches to attendance events.");

        return self::SUCCESS;
    }

    /**
     * Map the device raw state to an attendance direction.
     *
     * Values mirror the attendance event directions (1 in, -1 out,
     * 0 unknown) without referencing attendance classes, so this
     * command stays loadable when the attendance plugin is absent.
     */
    protected static function directionFor(int $rawState): int
    {
        return match ($rawState) {
            0       => 1,
            1       => -1,
            default => 0,
        };
    }
}
