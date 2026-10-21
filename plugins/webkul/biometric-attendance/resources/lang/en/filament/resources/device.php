<?php

return [
    'title' => 'Biometric Device',

    'navigation' => [
        'title' => 'Biometric Devices',
        'group' => 'Attendance',
    ],

    'form' => [
        'name'                   => 'Name',
        'host'                   => 'Host / IP',
        'host-helper-text'       => 'Device IP address or hostname reachable from this server on port 4370.',
        'port'                   => 'Port',
        'protocol'               => 'Protocol',
        'protocol-helper-text'   => 'Use TCP first. Some older panels only answer on UDP.',
        'comm-key'               => 'COMM Key',
        'comm-key-helper-text'   => 'Connection password from the device screen: Menu → COMM → PC Connection. Not the admin login password. Leave 0 when unset (default).',
        'timezone'               => 'Device Timezone',
        'timezone-helper-text'   => 'Wall-clock timezone of the device. Start typing to search, e.g. Asia/Riyadh.',
        'active'                 => 'Active',
    ],

    'table' => [
        'columns' => [
            'name'       => 'Name',
            'host'       => 'Host / IP',
            'port'       => 'Port',
            'protocol'   => 'Protocol',
            'active'     => 'Active',
            'last-sync'  => 'Last Sync',
            'created-at' => 'Created At',
        ],

        'actions' => [
            'test-connection' => [
                'label' => 'Test Connection',

                'notification' => [
                    'title'        => 'Device reachable',
                    'failed-title' => 'Connection failed',
                ],
            ],
            'sync-now' => [
                'label' => 'Sync Now',

                'notification' => [
                    'title'        => 'Sync completed',
                    'failed-title' => 'Sync failed',
                    'body'         => 'Stored :stored, already present :skipped, backfilled :backfilled.',
                ],
            ],
        ],
    ],
];
