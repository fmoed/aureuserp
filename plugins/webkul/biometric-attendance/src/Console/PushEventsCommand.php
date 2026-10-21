<?php

namespace Webkul\BiometricAttendance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Events\EventGateway;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\BiometricAttendance\Services\AttendanceBridge;

class PushEventsCommand extends Command
{
    /**
     * Direction pushed for every punch. The device `state` byte is the
     * verify mode (fingerprint, card, face), not IN/OUT, and `type` is not
     * proven yet on the UA300, so the direction stays unknown (0) until a
     * hardware check proves otherwise. Pairing ignores direction anyway.
     */
    public const UNKNOWN_DIRECTION = 0;

    protected $signature = 'biometric:push-events';

    protected $description = 'Push matched biometric punches into attendance events (rerun-safe, no pairing here)';

    public function handle(): int
    {
        if (! AttendanceBridge::isAvailable()) {
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
                self::UNKNOWN_DIRECTION,
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
}
