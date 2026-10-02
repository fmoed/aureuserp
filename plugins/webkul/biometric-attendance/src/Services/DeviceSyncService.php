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
     * The device dumps its whole log over the wire on every call — a
     * ZKTeco protocol limitation, not something the watermark can avoid.
     * Cheap re-syncs come from the stored last_sync_uid watermark instead:
     * records at or below it are skipped before any database write, and
     * firstOrCreate remains only as a safety net for races and reused uids.
     *
     * @return array{stored: int, skipped: int, backfilled: int, fetched: int}
     *
     * @throws ZktecoConnectionException
     */
    public function sync(Device $device): array
    {
        $records = $this->client->getAttendanceLogs($device);

        $watermark = $device->last_sync_uid;
        $maxSeen = $watermark;

        $stored = 0;
        $skipped = 0;

        $employeeIds = Employee::query()
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->pluck('id', 'barcode');

        foreach ($records as $record) {
            $uid = (int) $record->uid;

            $maxSeen = $maxSeen === null ? $uid : max($maxSeen, $uid);

            if ($watermark !== null && $uid <= $watermark) {
                $skipped++;

                continue;
            }

            $punch = Punch::firstOrCreate(
                [
                    'device_id'  => $device->id,
                    'device_uid' => $uid,
                ],
                [
                    'zk_user_id'  => $record->userId,
                    'employee_id' => $employeeIds->get($record->userId),
                    'punch_time'  => $record->recordTime !== '' ? $record->recordTime : null,
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

        foreach ($employeeIds as $barcode => $employeeId) {
            $backfilled += Punch::where('device_id', $device->id)
                ->whereNull('employee_id')
                ->where('zk_user_id', $barcode)
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
}
