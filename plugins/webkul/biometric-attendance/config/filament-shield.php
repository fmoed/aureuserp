<?php

use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\BiometricAttendance\Filament\Pages\ManageSync;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;

$basic = ['view_any', 'view', 'create', 'update'];
$delete = ['delete', 'delete_any'];

return [
    'resources' => [
        'manage' => [
            DeviceResource::class => [...$basic, ...$delete],
        ],
        'exclude' => [],
    ],

    'pages' => [
        'exclude' => [
            Configurations::class,
            ManageSync::class,
        ],
    ],
];
