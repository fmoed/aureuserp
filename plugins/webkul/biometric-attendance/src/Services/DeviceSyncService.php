<?php

namespace Webkul\BiometricAttendance\Services;

use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\Employee\Models\Employee;

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

        $employeeIds = $this->employeeIdsByUserId();

        foreach ($records as $record) {
            $uid = (int) $record->uid;

            $maxSeen = $maxSeen === null ? $uid : max($maxSeen, $uid);

            if (($watermark !== null && $uid <= $watermark) || $record->recordTime === '') {
                $skipped++;

                continue;
            }

            $punch = Punch::firstOrCreate(
                [
                    'device_id'  => $device->id,
                    'zk_user_id' => $record->userId,
                    'punch_time' => $record->recordTime,
                ],
                [
                    'device_uid'  => $uid,
                    'employee_id' => $employeeIds[$record->userId] ?? null,
                    'raw_state'   => (int) $record->state,
                    'raw_type'    => (int) $record->type,
                ]
            );

            if ($punch->wasRecentlyCreated) {
                $stored++;
            } else {
                $skipped++;
            }
        }

        $backfilled = 0;

        foreach ($employeeIds as $userId => $employeeId) {
            $backfilled += Punch::where('device_id', $device->id)
                ->whereNull('employee_id')
                ->where('zk_user_id', (string) $userId)
                ->update(['employee_id' => $employeeId]);
        }

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
     * Exact barcode => employee id. On duplicates the oldest employee
     * (lowest id) wins silently for HR cleanup.
     *
     * @return array<string, int>
     */
    protected function employeeIdsByUserId(): array
    {
        $map = [];

        $barcodes = Employee::query()
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id')
            ->pluck('barcode', 'id');

        foreach ($barcodes as $employeeId => $barcode) {
            $key = (string) $barcode;

            if (array_key_exists($key, $map)) {
                continue;
            }

            $map[$key] = $employeeId;
        }

        return $map;
    }
}
