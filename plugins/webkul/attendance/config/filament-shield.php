<?php

use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\Attendance\Filament\Pages\ManageAttendance;
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

    'pages' => [
        'exclude' => [
            Configurations::class,
            ManageAttendance::class,
        ],
    ],
];
