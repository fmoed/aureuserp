<?php

return [
    'title' => 'Remote Check-in',

    'navigation' => [
        'title' => 'Remote Check-in',
    ],

    'status' => [
        'no-employee'  => 'Your user account is not linked to an employee profile.',
        'not-eligible' => 'Remote check-in is only enabled for employees with a home work location.',
        'pending'      => 'Not checked in today',
        'checked-in'   => 'Checked in today at :in',
        'checked-out'  => 'Checked in at :in · Checked out at :out',
    ],

    'actions' => [
        'check-in' => [
            'label'            => 'Remote Check-in',
            'plan-note'        => 'Today\'s plan (optional)',
            'plan-placeholder' => 'Briefly list what you plan to work on today...',
            'success'          => 'Remote check-in recorded.',
        ],

        'check-out' => [
            'label'        => 'Remote Check-out',
            'summary-note' => 'End-of-day summary (optional)',
            'success'      => 'Remote check-out recorded.',
        ],

        'log-work' => [
            'label'                   => 'Log Today\'s Work',
            'items-label'             => 'Accomplished tasks',
            'add-item'                => 'Add another task',
            'project'                 => 'Project (optional)',
            'task'                    => 'Task (optional)',
            'new-task'                => 'Or create a new task',
            'task-state'              => 'Task status',
            'hours-spent'             => 'Hours spent',
            'description'             => 'Accomplished tasks',
            'description-placeholder' => 'Describe the tasks you accomplished today...',
            'success'                 => 'Today\'s work has been logged.',
        ],
    ],

    'table' => [
        'work-date'       => 'Work Date',
        'check-in'        => 'Check In',
        'check-out'       => 'Check Out',
        'no-checkout'     => 'No check-out',
        'source'          => 'Source',
        'accomplishments' => 'Logged Tasks & Notes',
    ],
];
