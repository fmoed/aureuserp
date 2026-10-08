<?php

use Webkul\Attendance\Filament\Resources\AttendanceEventResource;
use Webkul\Attendance\Filament\Resources\AttendanceResource;

$basic = ['view_any', 'view', 'create', 'update'];
$delete = ['delete', 'delete_any'];

return [
    'resources' => [
        'manage' => [
            AttendanceResource::class      => [...$basic, ...$delete],
            AttendanceEventResource::class => ['view_any', 'view'],
        ],
        'exclude' => [],
    ],
];
