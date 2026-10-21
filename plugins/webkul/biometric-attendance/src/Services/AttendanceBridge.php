<?php

namespace Webkul\BiometricAttendance\Services;

use Illuminate\Support\Facades\Schema;
use Webkul\Attendance\Events\EventGateway;

/**
 * Runtime check for the optional attendance plugin. Code presence alone
 * proves nothing in this monorepo (every plugin is autoloadable), so the
 * attendance tables must exist too.
 */
class AttendanceBridge
{
    public static function isAvailable(): bool
    {
        return class_exists(EventGateway::class) && Schema::hasTable('attendance_events');
    }
}
