<?php

return [
    'title' => 'Attendance Settings',

    'navigation' => [
        'title' => 'Attendance Settings',
    ],

    'sections' => [
        'register-scope' => [
            'title'       => 'Attendance Register & Report Scope',
            'description' => 'Control which employees are included in the attendance register and absence exports so employees without a check-in method do not appear as absent.',
        ],
        'governance' => [
            'title'       => 'Attendance Rules',
            'description' => 'Operational limits applied to pairing and manual entry.',
        ],
        'hours-policies' => [
            'title'       => 'Hours, Leave & Period Lock Policies',
            'description' => 'Defaults used when a shift does not set its own policy, plus the payroll lock date.',
        ],
    ],

    'links' => [
        'calendars' => 'Manage work schedules',
        'devices'   => 'Manage biometric devices',
    ],

    'form' => [
        'employee-scope' => [
            'label' => 'Displayed employees in attendance register',
        ],

        'count-pending-leave' => [
            'label' => 'Count pending leave as absent',
            'hint'  => 'Unauthorized absence stays an absence until the leave is approved.',
        ],

        'duplicate-punch-window' => [
            'label' => 'Duplicate punch window',
            'hint'  => 'Punches within this window of the accepted punch are treated as accidental double scans.',
        ],

        'max-shift-hours' => [
            'label' => 'Maximum shift hours',
            'hint'  => 'Manual check-outs and biometric pairing reject shifts longer than this.',
        ],

        'default-missing-checkout' => [
            'label' => 'Default missing checkout policy',
            'hint'  => 'Applied to shifts that do not choose their own missing checkout policy.',
        ],

        'half-day-threshold' => [
            'label' => 'Half-day threshold (%)',
            'hint'  => 'Working less than this percentage of scheduled daily hours marks the day as a half-day.',
        ],

        'holiday-policy' => [
            'label' => 'Public holiday policy',
            'hint'  => 'Controls whether company and shift public holidays automatically excuse attendance.',
        ],

        'early-arrival-policy' => [
            'label' => 'Early arrival policy',
            'hint'  => 'How minutes punched before the scheduled shift start are treated.',
        ],

        'max-early-offset' => [
            'label' => 'Maximum early arrival credit',
            'hint'  => 'Maximum early minutes allowed to offset early departure or count as overtime.',
        ],

        'mandatory-days-policy' => [
            'label' => 'Mandatory days policy',
            'hint'  => 'Whether mandatory days excuse attendance, require it, or only block leave requests.',
        ],

        'count-unpaid-leave' => [
            'label' => 'Count unpaid leave in absence rate',
            'hint'  => 'When off, approved unpaid leave stays outside the absence rate and payroll deduction.',
        ],

        'rounding-mode' => [
            'label' => 'Minute rounding',
            'hint'  => 'Rounds computed scheduled and worked minutes.',
        ],

        'attendance-lock-date' => [
            'label' => 'Attendance lock date',
            'hint'  => 'Rows up to this date cannot be edited manually or rebuilt automatically after payroll approval.',
        ],

        'seconds-suffix' => 'seconds',
        'minutes-suffix' => 'minutes',
        'hours-suffix'   => 'hours',

        'employee-scope-options' => [
            'all'                => 'All employees with a work schedule',
            'enrolled_or_active' => 'Employees with a Barcode (or check-in method) OR attendance activity in the last year (Recommended)',
            'enrolled'           => 'Employees with a Barcode (biometric ID) or active check-in method only',
            'recent_activity'    => 'Employees with attendance activity in the last year only',
        ],

        'employee-scope-descriptions' => [
            'all'                => 'Shows every employee assigned to a work schedule, even if they have no Barcode or attendance history.',
            'enrolled_or_active' => 'Includes employees who have a Barcode, are enrolled in any installed attendance plugin (e.g. biometric or remote attendance), or have any recorded attendance in the last 12 months.',
            'enrolled'           => 'Includes only employees who have a Barcode set on their profile or are enrolled in a registered attendance writer plugin.',
            'recent_activity'    => 'Includes only employees who have at least one attendance record or punch event from any attendance source in the last 12 months.',
        ],
    ],
];
