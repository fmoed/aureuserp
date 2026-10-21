<?php

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
];
