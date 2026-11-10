<?php

return [
    'title' => 'Working Schedules',

    'navigation' => [
        'title' => 'Working Schedules',
    ],

    'groups' => [
        'status'     => 'Status',
        'created-by' => 'Created By',
        'created-at' => 'Created At',
        'updated-at' => 'Updated At',
    ],

    'form' => [
        'sections' => [
            'general' => [
                'title'  => 'General Information',
                'fields' => [
                    'name'                  => 'Name',
                    'schedule-name'         => 'Schedule Name',
                    'schedule-name-tooltip' => 'Please write descriptive working schedule name.',
                    'timezone'              => 'Timezone',
                    'timezone-tooltip'      => 'Please select the timezone for the working schedule.',
                    'company'               => 'Company',
                    'shift-type'            => 'Shift Type',
                ],
            ],

            'windows' => [
                'title'          => 'Check-in / Check-out Windows & Pairing',
                'description'    => 'Acceptance windows that decide when the device opens check-in and closes check-out for this shift, including night shifts across midnight.',
                'minutes-suffix' => 'minutes',
                'overlap-error'  => 'The shift length plus both acceptance windows must stay under 24 hours.',
                'preview-in'     => 'Shift starts :start — Check-in accepted from :open',
                'preview-out'    => 'Shift ends :end — Check-out accepted until :close',
                'preview-split'  => 'Part :index (:start–:end): accepted :open to :close',
                'fields'         => [
                    'pairing-mode'    => 'Punch Pairing Mode',
                    'check-in-before' => 'Check-in Window Before Shift',
                    'check-out-after' => 'Check-out Window After Shift',
                ],
            ],

            'punctuality' => [
                'title'       => 'Punctuality & Early Arrival',
                'description' => 'Grace periods and how early arrivals interact with early departures.',
                'fields'      => [
                    'late-grace'           => 'Late Grace',
                    'early-leave-grace'    => 'Early Leave Grace',
                    'early-arrival-policy' => 'Early Arrival Policy',
                    'max-early-offset'     => 'Max Early Offset',
                ],
            ],

            'breaks' => [
                'title'       => 'Breaks',
                'description' => 'How break time is tracked or deducted during the shift.',
            ],

            'policies' => [
                'title'       => 'Shift Operational Policies (Windows, Grace & Overtime)',
                'description' => 'Configure check-in/check-out acceptance windows, late and early-leave grace periods, missing checkout policy, and overtime rules for this shift.',
                'fields'      => [
                    'break-policy'             => 'Break Policy',
                    'break-duration'           => 'Break Duration',
                    'break-trigger'            => 'Break Trigger',
                    'holiday-policy'           => 'Holiday Policy',
                    'missing-checkout-policy'  => 'Missing Checkout Policy',
                    'missing-checkout-inherit' => 'Inherit from attendance settings',
                    'half-day-threshold'       => 'Half-day Threshold',
                    'absent-threshold'         => 'Absence Threshold',
                    'overtime-enabled'         => 'Enable Overtime',
                    'min-overtime'             => 'Minimum Overtime',
                ],
            ],

            'configuration' => [
                'title'                   => 'Work Hours & Shift Periods',
                'split-min-periods-error' => 'A split shift requires at least two working periods (Part 1 and Part 2).',
                'hours-mismatch-error'    => 'The sum of the configured shift periods (:actual hours) does not match Hours Per Day (:expected hours).',
                'custom-schedule-hint'    => 'Note: this shift has customized hours for some days in the schedule below. Your per-day overrides will only be replaced if you change working days or periods here.',
                'fields'                  => [
                    'hours-per-day'                   => 'Hours Per Day',
                    'hours-per-day-suffix'            => 'Hours',
                    'full-time-required-hours'        => 'Full Time Required Hours',
                    'full-time-required-hours-suffix' => 'Hours Per Week',
                    'work-days'                       => 'Working Days',
                    'shift-periods'                   => 'Daily Shift Periods (Start & End Times)',
                    'period-from'                     => 'Start Time',
                    'period-to'                       => 'End Time',
                    'day-period'                      => 'Day Period',
                    'add-period'                      => 'Add another shift period',
                ],
            ],

            'flexibility' => [
                'title'  => 'Flexibility',
                'fields' => [
                    'status'                     => 'Status',
                    'two-weeks-calendar'         => 'Two Weeks Calendar',
                    'two-weeks-calendar-tooltip' => 'Enable alternating two-week work schedule.',
                    'flexible-hours'             => 'Flexible Hours',
                    'flexible-hours-tooltip'     => 'Allow employees to have flexible work hours.',
                ],
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'id'             => 'ID',
            'name'           => 'Schedule Name',
            'timezone'       => 'Timezone',
            'company'        => 'Company',
            'flexible-hours' => 'Flexible Hours',
            'status'         => 'Status',
            'daily-hours'    => 'Daily Hours',
            'created-by'     => 'Created By',
            'created-at'     => 'Created At',
            'updated-at'     => 'Updated At',
        ],

        'filters' => [
            'company'                  => 'Company',
            'is-active'                => 'Status',
            'two-week-calendar'        => 'Two Weeks Calendar',
            'flexible-hours'           => 'Flexible Hours',
            'timezone'                 => 'Timezone',
            'name'                     => 'Schedule Name',
            'attendance'               => 'Attendance',
            'created-by'               => 'Created By',
            'daily-hours'              => 'Daily Hours',
            'full-time-required-hours' => 'Full Time Required Hours',
            'updated-at'               => 'Updated At',
            'created-at'               => 'Created At',
        ],

        'groups' => [
            'name'           => 'Schedule Name',
            'status'         => 'Status',
            'timezone'       => 'Timezone',
            'flexible-hours' => 'Flexible Hours',
            'daily-hours'    => 'Daily Hours',
            'created-by'     => 'Created By',
            'created-at'     => 'Created At',
            'updated-at'     => 'Updated At',
        ],

        'actions' => [
            'restore' => [
                'notification' => [
                    'title' => 'Calendar Plan restored',
                    'body'  => 'The calendar plan has been restored successfully.',
                ],
            ],

            'delete' => [
                'notification' => [
                    'title' => 'Calendar Plan deleted',
                    'body'  => 'The calendar plan has been deleted successfully.',
                ],
            ],

            'force-delete' => [
                'notification' => [
                    'title' => 'Calendar Plan force deleted',
                    'body'  => 'The calendar plan has been force deleted successfully.',
                ],
            ],
        ],

        'bulk-actions' => [
            'restore' => [
                'notification' => [
                    'title' => 'Calendar Plans restored',
                    'body'  => 'The calendar plans has been restored successfully.',
                ],
            ],

            'delete' => [
                'notification' => [
                    'title' => 'Calendar Plans deleted',
                    'body'  => 'The calendar plans has been deleted successfully.',
                ],
            ],

            'force-delete' => [
                'notification' => [
                    'title' => 'Calendar Plans force deleted',
                    'body'  => 'The calendar plans has been force deleted successfully.',
                ],
            ],
        ],
    ],

    'infolist' => [
        'sections' => [
            'general' => [
                'title'   => 'General Information',
                'entries' => [
                    'name'                  => 'Name',
                    'schedule-name'         => 'Schedule Name',
                    'schedule-name-tooltip' => 'Please write descriptive working schedule name.',
                    'timezone'              => 'Timezone',
                    'timezone-tooltip'      => 'Please select the timezone for the working schedule.',
                    'company'               => 'Company',
                ],
            ],

            'configuration' => [
                'title'   => 'Work Hours Configuration',
                'entries' => [
                    'hours-per-day'                   => 'Hours Per Day',
                    'hours-per-day-suffix'            => ' Hours',
                    'full-time-required-hours'        => 'Full Time Required Hours',
                    'full-time-required-hours-suffix' => ' Hours Per Week',
                ],
            ],

            'flexibility' => [
                'title'   => 'Flexibility',
                'entries' => [
                    'status'                     => 'Status',
                    'two-weeks-calendar'         => 'Two Weeks Calendar',
                    'two-weeks-calendar-tooltip' => 'Enable alternating two-week work schedule.',
                    'flexible-hours'             => 'Flexible Hours',
                    'flexible-hours-tooltip'     => 'Allow employees to have flexible work hours.',
                ],
            ],
        ],
    ],
];
