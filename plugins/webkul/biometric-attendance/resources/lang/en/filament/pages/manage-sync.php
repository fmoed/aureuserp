<?php

return [
    'title' => 'Biometric Sync',

    'navigation' => [
        'title' => 'Biometric Sync',
    ],

    'sections' => [
        'interval' => 'Sync interval',
        'status'   => 'Sync status',
    ],

    'form' => [
        'cadence' => [
            'label' => 'Sync interval',
            'hint'  => 'How often devices are read. Applies to the next scheduled run.',
        ],

        'cadence-options' => [
            'fifteen-minutes' => 'Every 15 minutes',
            'thirty-minutes'  => 'Every 30 minutes',
            'hourly'          => 'Every hour',
            'daily'           => 'Once a day (midnight)',
        ],
    ],

    'info' => [
        'next-run' => [
            'label'   => 'Next run',
            'unknown' => 'Unknown — check the server scheduler.',
        ],

        'last-sync' => [
            'label' => 'Last successful sync (any device)',
            'never' => 'Never synced yet.',
        ],

        'ops-note' => 'Automatic runs need the server scheduler (`schedule:run` every minute, or `schedule:work` locally).',
    ],
];
