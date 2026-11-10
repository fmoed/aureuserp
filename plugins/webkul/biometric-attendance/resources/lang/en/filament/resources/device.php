<?php

return [
    'title' => 'Biometric Device',

    'navigation' => [
        'title' => 'Biometric Devices',
        'group' => 'Attendance',
    ],

    'form' => [
        'name'                   => 'Name',
        'company'                => 'Company',
        'host'                   => 'Host / IP',
        'host-helper-text'       => 'Device IP address or hostname reachable from this server on port 4370.',
        'port'                   => 'Port',
        'protocol'               => 'Protocol',
        'protocol-helper-text'   => 'Use TCP first. Some older panels only answer on UDP.',
        'comm-key'               => 'COMM Key',
        'comm-key-helper-text'   => 'Connection password from the device screen: Menu → COMM → PC Connection. Not the admin login password. Leave 0 when unset (default).',
        'timezone'               => 'Device Timezone',
        'timezone-helper-text'   => 'Wall-clock timezone of the device. Start typing to search, e.g. Asia/Riyadh.',
        'punch-direction-mode'   => 'Punch direction',
        'punch-direction-modes'  => [
            'auto-toggle'      => 'Auto (unknown direction)',
            'strict-direction' => 'Strict (infer from punch type)',
            'force-in'         => 'Always check-in',
            'force-out'        => 'Always check-out',
        ],
        'punch-direction-mode-helper-text' => 'How pushed punches are labelled. Auto keeps them direction-less and lets pairing decide; strict derives the direction from the device record type; force-in/force-out stamp every punch.',
        'active'                           => 'Active',
        'sections'                         => [
            'general'    => ['title' => 'Device & punch direction'],
            'connection' => ['title' => 'Network & connection'],
            'status'     => [
                'title'     => 'Sync status',
                'last-sync' => 'Last sync',
                'last-error'=> 'Last error',
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'name'                    => 'Name',
            'company'                 => 'Company',
            'host'                    => 'Host / IP',
            'port'                    => 'Port',
            'protocol'                => 'Protocol',
            'active'                  => 'Active',
            'last-sync'               => 'Last Sync',
            'last-error'              => 'Last error',
            'created-at'              => 'Created At',
        ],

        'filters' => [
            'company'           => 'Company',
            'active'            => 'Active',
        ],

        'actions' => [
            'test-connection' => [
                'label' => 'Test Connection',

                'notification' => [
                    'title'        => 'Device reachable',
                    'failed-title' => 'Connection failed',
                    'missing-host' => 'Enter the device host/IP address first.',
                ],
            ],
            'sync-now' => [
                'label' => 'Sync Now',

                'notification' => [
                    'title'         => 'Sync completed',
                    'failed-title'  => 'Sync failed',
                    'partial-title' => 'Sync completed with warnings',
                    'body'          => 'Stored :stored, already present :skipped, backfilled :backfilled.',
                    'partial-body'  => 'Stored :stored, already present :skipped, backfilled :backfilled, failed to push :failed.',
                ],
            ],
        ],
    ],
];
