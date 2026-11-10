<?php

namespace Webkul\BiometricAttendance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Scopes\CompanyScope;

class DeviceSyncService
{
    public function __construct(protected ZktecoClientInterface $client) {}

    /**
     * Download one device's log and store new raw punches.
     *
     * The device dumps its whole log on every call (ZKTeco protocol). The
     * last_sync_uid watermark only skips work when it is still trustworthy;
     * after a log clear the full log is rescanned. Punch identity is the
     * natural key (device, user, wall-clock time), so rescans never
     * duplicate rows.
     *
     * @return array{stored: int, skipped: int, backfilled: int, fetched: int}
     *
     * @throws ZktecoConnectionException
     */
    public function sync(Device $device): array
    {
        $records = $this->client->getAttendanceLogs($device);

        $watermark = $this->trustedWatermark($device, $records);
        $maxSeen = $watermark;

        $stored = 0;
        $skipped = 0;

        $employeeIds = $this->employeeIdsByUserId($device);

        foreach ($records as $record) {
            $punchTime = $this->validPunchTime($record->recordTime);

            if ($punchTime === null) {
                $skipped++;

                continue;
            }

            $uid = (int) $record->uid;

            $maxSeen = $maxSeen === null ? $uid : max($maxSeen, $uid);

            if ($watermark !== null && $uid <= $watermark) {
                $skipped++;

                continue;
            }

            $punch = Punch::firstOrCreate(
                [
                    'device_id'  => $device->id,
                    'zk_user_id' => $record->userId,
                    'punch_time' => $punchTime,
                ],
                [
                    'device_uid'  => $uid,
                    'employee_id' => $employeeIds[static::normalizeUserId((string) $record->userId)] ?? null,
                    'raw_state'   => (int) $record->state,
                    'raw_type'    => (int) $record->type,
                ]
            );

            if ($punch->wasRecentlyCreated) {
                $stored++;
            } else {
                $skipped++;

                // The device renumbered this punch (or the log was rebuilt);
                // keep the watermark anchor resolving to the stored row.
                if ((int) $punch->device_uid !== $uid) {
                    $punch->update(['device_uid' => $uid]);
                }
            }
        }

        $backfilled = $this->backfillUnmatched($device, $employeeIds);

        $device->update([
            'last_sync_uid' => $maxSeen,
            'last_sync_at'  => now(),
            'last_error'    => null,
        ]);

        return [
            'stored'     => $stored,
            'skipped'    => $skipped,
            'backfilled' => $backfilled,
            'fetched'    => count($records),
        ];
    }

    /**
     * Re-match this device's orphan punches against the current employee
     * barcodes. Shared by the sync run and the device page's "re-match"
     * action so both paths use one definition.
     *
     * @param  array<string, int>|null  $employeeIds  normalized barcode => employee id
     */
    public function backfillUnmatched(Device $device, ?array $employeeIds = null): int
    {
        $employeeIds ??= $this->employeeIdsByUserId($device);

        $backfilled = 0;

        $userIds = Punch::query()
            ->where('device_id', $device->getKey())
            ->whereNull('employee_id')
            ->whereNotNull('zk_user_id')
            ->distinct()
            ->pluck('zk_user_id');

        foreach ($userIds as $userId) {
            $employeeId = $employeeIds[static::normalizeUserId((string) $userId)] ?? null;

            if ($employeeId === null) {
                continue;
            }

            $backfilled += Punch::query()
                ->where('device_id', $device->getKey())
                ->whereNull('employee_id')
                ->where('zk_user_id', $userId)
                ->update(['employee_id' => $employeeId]);
        }

        return $backfilled;
    }

    /**
     * Match every stored orphan punch whose device user id equals the
     * employee's barcode. Runs without any device connection, so a barcode
     * added (or corrected) in HR immediately recovers the historical
     * punches already stored locally.
     */
    public function backfillForEmployee(Employee $employee): int
    {
        $barcode = trim((string) $employee->barcode);

        if ($barcode === '') {
            return 0;
        }

        $normalizedBarcode = static::normalizeUserId($barcode);

        $deviceQuery = fn ($query) => $query->when(
            $employee->company_id !== null,
            fn ($inner) => $inner->where(
                fn ($company) => $company->where('company_id', $employee->company_id)->orWhereNull('company_id')
            ),
        );

        $userIds = Punch::query()
            ->whereNull('employee_id')
            ->whereNotNull('zk_user_id')
            ->whereHas('device', $deviceQuery)
            ->distinct()
            ->pluck('zk_user_id')
            ->filter(fn (mixed $userId): bool => static::normalizeUserId((string) $userId) === $normalizedBarcode)
            ->values()
            ->all();

        if ($userIds === []) {
            return 0;
        }

        return Punch::query()
            ->whereNull('employee_id')
            ->whereIn('zk_user_id', $userIds)
            ->update(['employee_id' => $employee->getKey()]);
    }

    /**
     * Re-match orphans across every device, used by the sync chain so a
     * device that is offline never blocks matching punches already stored.
     */
    public function backfillAllUnmatched(): int
    {
        $backfilled = 0;

        Device::withoutGlobalScope(CompanyScope::class)
            ->orderBy('id')
            ->each(function (Device $device) use (&$backfilled): void {
                $backfilled += $this->backfillUnmatched($device);
            });

        return $backfilled;
    }

    /**
     * The stored watermark is trusted only when the punch it points to is
     * still on the device unchanged; otherwise the log was cleared or
     * replaced and everything is rescanned.
     *
     * @param  PunchRecord[]  $records
     */
    protected function trustedWatermark(Device $device, array $records): ?int
    {
        $watermark = $device->last_sync_uid;

        if ($watermark === null) {
            return null;
        }

        $anchor = Punch::query()
            ->where('device_id', $device->id)
            ->where('device_uid', $watermark)
            ->latest('id')
            ->first();

        if (! $anchor) {
            return null;
        }

        foreach ($records as $record) {
            if ((int) $record->uid !== $watermark) {
                continue;
            }

            return $record->userId === $anchor->zk_user_id && $record->recordTime === $anchor->getRawOriginal('punch_time')
                ? $watermark
                : null;
        }

        return null;
    }

    /**
     * Strip surrounding spaces and leading zeros so `00111` and `111`
     * resolve to the same device user.
     */
    public static function normalizeUserId(string $userId): string
    {
        return ltrim(trim($userId), '0') ?: '0';
    }

    /**
     * Normalized `Y-m-d H:i:s` wall-clock value, or null when the device
     * returned an empty or corrupt stamp (`0000-00-00 00:00:00`). The
     * round trip rejects dates the parser silently normalizes.
     */
    protected function validPunchTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d H:i:s', $value);
        } catch (Throwable) {
            return null;
        }

        return $parsed->format('Y-m-d H:i:s') === $value ? $value : null;
    }

    /**
     * Normalized barcode => employee id. When a device is given, employees
     * are limited to that device's company (plus unassigned employees). On
     * duplicates the oldest employee (lowest id) wins and a warning is logged
     * for HR cleanup.
     *
     * @return array<string, int>
     */
    public function employeeIdsByUserId(?Device $device = null): array
    {
        $map = [];

        $query = Employee::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id');

        if ($device?->company_id) {
            $query->where(fn ($query) => $query
                ->where('company_id', $device->company_id)
                ->orWhereNull('company_id'));
        }

        foreach ($query->pluck('barcode', 'id') as $employeeId => $barcode) {
            $key = static::normalizeUserId((string) $barcode);

            if (array_key_exists($key, $map)) {
                Log::warning('Duplicate employee barcode encountered during biometric matching.', [
                    'barcode'          => $key,
                    'employee_id'      => $employeeId,
                    'kept_employee_id' => $map[$key],
                ]);

                continue;
            }

            $map[$key] = $employeeId;
        }

        return $map;
    }
}
