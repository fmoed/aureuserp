<?php

return [
    'title' => 'Attendance Overview',

    'navigation' => [
        'title' => 'Attendance Overview',
    ],

    'form' => [
        'employee'       => 'Employee',
        'all-employees'  => 'All employees',
        'date-from'      => 'From',
        'date-to'        => 'To',
        'range-too-wide' => 'The range must not exceed :days days.',
    ],

    'tabs' => [
        'daily'   => 'Daily',
        'monthly' => 'Monthly',
    ],

    'table' => [
        'columns' => [
            'date'      => 'Date',
            'employee'  => 'Employee',
            'status'    => 'Status',
            'check-in'  => 'Check In',
            'check-out' => 'Check Out',
            'minutes'   => 'Worked',
        ],

        'empty' => 'No workdays in this range.',
    ],

    'statuses' => [
        'present'  => 'Present',
        'absent'   => 'Absent',
        'pending'  => 'Pending',
        'half-day' => 'Half day',
    ],

    'grid' => [
        'legend' => 'A absent · L on leave · P pending · H holiday · blank present.',
    ],

    'export' => 'Absence Report',
];
