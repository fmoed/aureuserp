<?php

return [
    'title' => 'Monthly Attendance Register',

    'navigation' => [
        'title' => 'Monthly Attendance Register',
    ],

    'form' => [
        'heading'        => 'Register filters',
        'employee'       => 'Employee',
        'all-employees'  => 'All employees',
        'view-mode'      => 'Time range',
        'mode-month'     => 'Month',
        'mode-range'     => 'Custom range',
        'mode-year'      => 'Year',
        'month'          => 'Month',
        'year'           => 'Year',
        'year-hint'      => 'Data available for the last two years.',
        'date-from'      => 'From',
        'date-to'        => 'To',
        'range-hint'     => 'Maximum :days days.',
        'apply'          => 'Apply',
        'reset'          => 'Reset',
        'range-too-wide' => 'The range must not exceed :days days.',
        'month-future'   => 'The selected month is in the future.',
    ],

    'table' => [
        'columns' => [
            'employee' => 'Employee',
            'schedule' => 'Work schedule',
            'rate'     => 'Absence rate',
            'workdays' => 'Workdays',
        ],

        'empty' => 'No workdays in this range. Employees without a work schedule are hidden.',

        'view' => [
            'totals' => 'Employee summary',
            'days'   => 'Daily register',
        ],
    ],

    'statuses' => [
        'present'    => 'Present',
        'absent'     => 'Absent',
        'on-leave'   => 'On leave',
        'pending'    => 'Pending',
        'holiday'    => 'Holiday',
        'half-day'   => 'Half day',
        'weekly-off' => 'Weekly off',
        'future'     => 'Upcoming',
    ],

    'empty' => [
        'heading' => 'No workdays to show.',
    ],

    'grid' => [
        'summary'      => 'Summary',
        'pending-note' => 'Pending leave counts as absent until approved.',
        'chart'        => 'Present vs absent per day',
        'legend'       => 'Status guide',
        'totals'       => [
            'employees' => 'Employees',
            'present'   => 'Present days',
            'absent'    => 'Absent days',
            'on-leave'  => 'Leave days',
            'pending'   => 'Pending',
            'holidays'  => 'Holidays',
            'rate'      => 'Absence rate',
        ],
    ],

    'export' => 'Absence summary (one row per employee)',

    'export-detail' => 'Absence detail (one row per workday)',
];
