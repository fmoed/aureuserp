<?php

namespace Webkul\BiometricAttendance\Services;

/**
 * Single raw attendance record exactly as returned by the device library.
 *
 * Values are kept as strings (no int casting) so Phase 2 can analyze the
 * real on-wire format (leading zeros, type/state semantics) before freezing
 * any matching or pairing logic.
 */
readonly class PunchRecord
{
    public function __construct(
        public string $uid = '',
        public string $userId = '',
        public string $state = '',
        public string $recordTime = '',
        public string $type = '',
        public string $deviceIp = '',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uid: (string) ($data['uid'] ?? ''),
            userId: (string) ($data['user_id'] ?? ''),
            state: (string) ($data['state'] ?? ''),
            recordTime: (string) ($data['record_time'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            deviceIp: (string) ($data['device_ip'] ?? ''),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'uid'         => $this->uid,
            'user_id'     => $this->userId,
            'state'       => $this->state,
            'record_time' => $this->recordTime,
            'type'        => $this->type,
            'device_ip'   => $this->deviceIp,
        ];
    }
}
