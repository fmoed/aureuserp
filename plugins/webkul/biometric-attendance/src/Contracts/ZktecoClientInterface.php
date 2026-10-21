<?php

namespace Webkul\BiometricAttendance\Contracts;

use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Services\DeviceInfo;
use Webkul\BiometricAttendance\Services\PunchRecord;
use Webkul\BiometricAttendance\Services\ZktecoConnectionException;

interface ZktecoClientInterface
{
    /**
     * Connect to the device, read identification data, disconnect.
     *
     * Read-only: must never change device state.
     *
     * @throws ZktecoConnectionException
     */
    public function getDeviceInfo(Device $device): DeviceInfo;

    /**
     * Connect to the device, download attendance logs, disconnect.
     *
     * Read-only: must never clear logs or change device state.
     *
     * @return PunchRecord[]
     *
     * @throws ZktecoConnectionException
     */
    public function getAttendanceLogs(Device $device): array;
}
