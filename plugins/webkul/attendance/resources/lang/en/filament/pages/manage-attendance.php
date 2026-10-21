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
    ],

    'form' => [
        'employee-scope' => [
            'label' => 'Displayed employees in attendance register',
        ],

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
