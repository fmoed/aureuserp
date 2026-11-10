<?php

return [
    'title' => 'Attendance',

    'navigation' => [
        'title' => 'Attendance records',
    ],

    'form' => [
        'employee'                   => 'Employee',
        'work-date'                  => 'Work Date',
        'work-date-hint'             => 'One manual record per day. Employee and date are locked after saving — edit the times instead.',
        'duplicate-day'              => ':employee already has a manual record on :date. Edit it instead of creating a new one.',
        'check-in'                   => 'Check In',
        'check-in-not-in-work-date'  => 'Check-in must be within the work date.',
        'clock-hint'                 => 'Enter times in :zone.',
        'check-out'                  => 'Check Out',
        'check-out-before-check-in'  => 'Check-out must be after check-in.',
        'check-out-not-in-work-date' => 'Check-out must be within :hours hours of check-in.',
        'checkout-hint'              => 'Completes the open row. The time is read in the employee time zone and must be within :hours hours of check-in.',
        'long-shift-warning'         => 'Heads up: this shift is :hours hours long.',
        'source'                     => 'Source',
        'source-manual'              => 'Manual',
    ],

    'table' => [
        'columns' => [
            'employee'   => 'Employee',
            'work-date'  => 'Work Date',
            'check-in'   => 'Check In',
            'check-out'  => 'Check Out',
            'next-day'   => 'Overnight',
            'worked'     => 'Worked',
            'estimated'  => 'Estimated',
            'source'     => 'Source',
            'created-at' => 'Created At',
        ],

        'filters' => [
            'employee'         => 'Employee',
            'department'       => 'Department',
            'calendar'         => 'Work schedule',
            'source'           => 'Source',
            'work-date'        => 'Work Date',
            'preset'           => 'Quick Period',
            'preset-hint'      => 'Choosing a quick period ignores From/To below.',
            'preset-today'     => 'Today',
            'preset-this-week' => 'This Week',
            'preset-this-month'=> 'This Month',
            'date-from'        => 'From',
            'date-to'          => 'To',
            'timezone-tip'     => 'Employee time (:zone)',
            'session-status'   => 'Session status',
            'status-open'      => 'Open (no check-out)',
            'status-closed'    => 'Closed',
            'status-long'      => 'Long shift',
        ],

        'toolbar-actions' => [
            'export'          => 'Export',
            'export-selected' => 'Export selected',
        ],

        'bulk-actions' => [
            'deleted' => ':count attendance records deleted.',
            'skipped' => ':count records skipped (device-sourced rows cannot be deleted).',
        ],

        'record-actions' => [
            'add-checkout' => 'Add Check-Out',
        ],
    ],

    'infolist' => [
        'sections' => [
            'record' => [
                'title' => 'Attendance record',
            ],
            'events' => [
                'title'       => 'Punch events',
                'description' => 'Raw punches paired into this attendance row.',
            ],
        ],

        'entries' => [
            'employee'  => 'Employee',
            'work-date' => 'Work date',
            'check-in'  => 'Check in',
            'check-out' => 'Check out',
            'worked'    => 'Worked',
            'source'    => 'Source',
            'timezone'  => 'Employee time zone',
        ],

        'events' => [
            'empty'      => 'No punches are linked to this record yet.',
            'punched-at' => 'Punch time',
            'direction'  => 'Direction',
            'source'     => 'Source',
            'source-ref' => 'Source reference',
        ],
    ],
];
