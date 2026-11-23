<?php

return [
    'title' => 'Remote Attendance Settings',

    'navigation' => [
        'title' => 'Remote Attendance',
    ],

    'sections' => [
        'eligibility' => [
            'title'       => 'Remote Eligibility & Work Locations',
            'description' => 'Choose which employees can check in remotely and receive morning reminders.',
        ],
        'session' => [
            'title'       => 'Session & Check-out Policy',
            'description' => 'Control whether remote employees only check in or must also record check-out.',
        ],
        'reminders' => [
            'title'       => 'Morning Reminder Schedule',
            'description' => 'Automated email and in-app notification sent on scheduled workdays (requires scheduler & queue worker).',
        ],
    ],

    'actions' => [
        'test-reminder' => [
            'label'    => 'Send Test Reminder to Me',
            'success'  => 'Test reminder sent to your account.',
            'sent-to'  => 'Sent to :email.',
            'log-note' => 'Mail driver is set to "log" — check storage/logs/laravel.log.',
            'no-email' => 'Your user account does not have an email address configured.',
            'failed'   => 'Could not deliver test email',
        ],
    ],

    'form' => [
        'only-home-location' => [
            'label' => 'Restrict remote check-in by work location',
            'hint'  => 'Turn off to allow all active employees regardless of work location.',
        ],
        'allowed-location-types' => [
            'label' => 'Allowed location types',
            'hint'  => 'Employees assigned to a work location of any selected type (default: Home) are eligible.',
        ],
        'allowed-work-locations' => [
            'label' => 'Specific work locations (optional)',
            'hint'  => 'Also allow employees assigned to these specific locations regardless of type.',
        ],
        'require-checkout' => [
            'label' => 'Require remote check-out',
            'hint'  => 'Leave disabled for check-in only (full scheduled hours are credited automatically when the shift uses Auto-credit scheduled).',
        ],
        'reminder-enabled' => [
            'label' => 'Enable morning check-in reminders',
            'hint'  => 'Skips employees on approved leave, holidays, days off, or already checked in.',
        ],
        'reminder-time' => [
            'label' => 'Reminder time (employee local clock)',
            'hint'  => '24-hour HH:MM format (default 09:00) in each employee\'s timezone.',
        ],
    ],
];
