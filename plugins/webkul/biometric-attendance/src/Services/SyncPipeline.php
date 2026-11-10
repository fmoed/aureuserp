<?php

namespace Webkul\BiometricAttendance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Attendance\Events\EventGateway;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Services\EventPairingService;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * Single source of truth for the "sync devices → push punches → pair
 * attendance rows" chain shared by the console command, the header action
 * and the per-device row action.
 */
class SyncPipeline
{
    /**
     * Default direction pushed for a punch. The device `state` byte is the
     * verify mode (fingerprint, card, face), not IN/OUT, so `auto_toggle`
     * keeps the direction unknown (0) and pairing ignores it anyway. Devices
     * can opt into a fixed or raw-type-derived direction through
     * `punch_direction_mode`.
     */
    public const UNKNOWN_DIRECTION = 0;

    public function __construct(protected DeviceSyncService $deviceSync) {}

    /**
     * Sync every active device, then optionally push and pair. A null
     * company list is the console/scheduler path and covers all tenants
     * (company scope bypassed); the UI passes its active company ids so a
     * button press never flushes another tenant's devices or backlog.
     *
     * @param  list<int>|null  $companyIds
     * @return array{
     *     total: int,
     *     succeeded: int,
     *     failed: int,
     *     stored: int,
     *     skipped: int,
     *     backfilled: int,
     *     pushed: int,
     *     push_failed: int,
     *     devices: list<array{name: string, result: ?array, error: ?string}>,
     *     push_errors: list<string>
     * }
     */
    public function syncAll(bool $chain = false, ?array $companyIds = null): array
    {
        $devices = Device::withoutGlobalScope(CompanyScope::class)
            ->where('is_active', true)
            ->when($companyIds !== null, fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->whereIn('company_id', $companyIds)
                    ->orWhereNull('company_id')
            ))
            ->orderBy('id')
            ->get();

        $summary = [
            'total'       => $devices->count(),
            'succeeded'   => 0,
            'failed'      => 0,
            'stored'      => 0,
            'skipped'     => 0,
            'backfilled'  => 0,
            'pushed'      => 0,
            'push_failed' => 0,
            'devices'     => [],
            'push_errors' => [],
        ];

        foreach ($devices as $device) {
            try {
                $result = $this->deviceSync->sync($device);
            } catch (Throwable $e) {
                $device->update(['last_error' => $e->getMessage()]);

                $summary['failed']++;

                $summary['devices'][] = [
                    'name'   => $device->name,
                    'result' => null,
                    'error'  => $e->getMessage(),
                ];

                continue;
            }

            $summary['succeeded']++;
            $summary['stored'] += $result['stored'];
            $summary['skipped'] += $result['skipped'];
            $summary['backfilled'] += $result['backfilled'];

            $summary['devices'][] = [
                'name'   => $device->name,
                'result' => $result,
                'error'  => null,
            ];
        }

        // Local matching must not depend on a reachable device: punches
        // stored by an earlier sync still resolve to barcodes added since.
        $summary['backfilled'] += $this->deviceSync->backfillAllUnmatched();

        if ($chain) {
            $push = $this->pushPendingPunches(companyIds: $companyIds);

            $summary['pushed'] = $push['pushed'];
            $summary['push_failed'] = $push['failed'];
            $summary['push_errors'] = $push['errors'];

            $this->pairAttendance();
        }

        return $summary;
    }

    /**
     * Sync one device, then push its matched punches and pair attendance.
     *
     * @return array{stored: int, skipped: int, backfilled: int, fetched: int, pushed: int, push_failed: int}
     */
    public function syncDevice(Device $device): array
    {
        $lock = Cache::lock("biometric:sync-device:{$device->id}", 120);

        return $lock->get(function () use ($device): array {
            $result = $this->deviceSync->sync($device);

            $push = $this->pushPendingPunches($device);

            $result['pushed'] = $push['pushed'];
            $result['push_failed'] = $push['failed'];

            $this->pairAttendance();

            return $result;
        }) ?? [
            'stored'      => 0,
            'skipped'     => 0,
            'backfilled'  => 0,
            'fetched'     => 0,
            'pushed'      => 0,
            'push_failed' => 0,
        ];
    }

    /**
     * Push every unmatched-free punch once. A single failing punch is
     * logged and skipped so the rest of the batch still reaches attendance.
     * Passing a device limits the batch to that device's own punches, so a
     * per-device sync never flushes another tenant's backlog. Passing
     * company ids limits the batch to those tenants (plus company-less
     * devices) for a UI-triggered sync.
     *
     * @param  list<int>|null  $companyIds
     * @return array{pushed: int, failed: int, errors: list<string>}
     */
    public function pushPendingPunches(?Device $device = null, ?array $companyIds = null, ?Employee $employee = null): array
    {
        if (! AttendanceBridge::isAvailable()) {
            return ['pushed' => 0, 'failed' => 0, 'errors' => []];
        }

        $punches = Punch::query()
            ->whereNotNull('employee_id')
            ->whereNotNull('punch_time')
            ->whereNull('pushed_at')
            ->when($device, fn ($query) => $query->where('device_id', $device->getKey()))
            ->when($employee, fn ($query) => $query->where('employee_id', $employee->getKey()))
            ->when($companyIds !== null, fn ($query) => $query->whereHas(
                'device',
                fn ($deviceQuery) => $deviceQuery->where(
                    fn ($inner) => $inner
                        ->whereIn('company_id', $companyIds)
                        ->orWhereNull('company_id')
                )
            ))
            ->with('device')
            ->orderBy('punch_time')
            ->orderBy('id')
            ->get();

        $pushed = 0;
        $failed = 0;
        $errors = [];

        foreach ($punches as $punch) {
            try {
                $deviceTimezone = $punch->device?->timezone ?: config('app.timezone');
                $rawPunchTime = $punch->getRawOriginal('punch_time');

                $punchedAtUtc = Carbon::parse($rawPunchTime, $deviceTimezone)->setTimezone('UTC')->format('Y-m-d H:i:s');

                EventGateway::push(
                    $punch->employee_id,
                    $punchedAtUtc,
                    $this->directionFor($punch),
                    "biometric-attendance:{$punch->device_id}",
                    "punch:{$punch->getKey()}",
                    $punch->device?->name,
                );

                $punch->update(['pushed_at' => now()]);

                $pushed++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "punch:{$punch->getKey()} — {$e->getMessage()}";

                Log::error('Failed to push biometric punch to attendance.', [
                    'punch_id' => $punch->getKey(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return ['pushed' => $pushed, 'failed' => $failed, 'errors' => $errors];
    }

    public function pairAttendance(): void
    {
        if (AttendanceBridge::isAvailable()) {
            Artisan::call('attendance:process-events');
        }
    }

    /**
     * Recover an employee's previously stored, still-orphan punches and
     * pair them immediately — no device connection involved.
     *
     * @return array{backfilled: int, pushed: int, push_failed: int, errors: list<string>, rows: list<int>}
     */
    public function syncEmployeeRetroactively(Employee $employee): array
    {
        $backfilled = $this->deviceSync->backfillForEmployee($employee);

        $push = $this->pushPendingPunches(employee: $employee);

        $rows = [];

        if (AttendanceBridge::isAvailable()) {
            $result = app(EventPairingService::class)->rebuild($employee, 'biometric-attendance');

            $rows = $result['rows'];
        }

        return [
            'backfilled'  => $backfilled,
            'pushed'      => $push['pushed'],
            'push_failed' => $push['failed'],
            'errors'      => $push['errors'],
            'rows'        => $rows,
        ];
    }

    protected function directionFor(Punch $punch): int
    {
        return match ($punch->device?->punch_direction_mode) {
            'force_in'         => AttendanceEvent::DIRECTION_IN,
            'force_out'        => AttendanceEvent::DIRECTION_OUT,
            'strict_direction' => match ((int) $punch->raw_type) {
                0, 4    => AttendanceEvent::DIRECTION_IN,
                1, 5    => AttendanceEvent::DIRECTION_OUT,
                default => self::UNKNOWN_DIRECTION,
            },
            default => self::UNKNOWN_DIRECTION,
        };
    }
}
