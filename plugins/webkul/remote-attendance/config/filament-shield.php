<?php

use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\RemoteAttendance\Filament\Pages\ManageRemoteAttendance;

return [
    'resources' => [
        'manage'  => [],
        'exclude' => [],
    ],

    'pages' => [
        'exclude' => [
            Configurations::class,
            ManageRemoteAttendance::class,
        ],
    ],
];
