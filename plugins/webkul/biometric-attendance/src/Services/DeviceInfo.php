<?php

namespace Webkul\BiometricAttendance\Services;

/**
 * Identification data read from a ZKTeco device (read-only).
 */
readonly class DeviceInfo
{
    public function __construct(
        public ?string $deviceName = null,
        public ?string $serialNumber = null,
        public ?string $firmware = null,
        public ?string $platform = null,
        public ?string $vendorName = null,
        public ?string $osVersion = null,
        public ?string $deviceTime = null,
        public ?string $host = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'device_name'   => $this->deviceName,
            'serial_number' => $this->serialNumber,
            'firmware'      => $this->firmware,
            'platform'      => $this->platform,
            'vendor'        => $this->vendorName,
            'os_version'    => $this->osVersion,
            'device_time'   => $this->deviceTime,
            'host'          => $this->host,
        ];
    }
}
