<?php

return [
    'columns' => [
        'employee'  => 'Employee',
        'work-date' => 'Work Date',
        'check-in'  => 'Check In',
        'check-out' => 'Check Out',
        'scheduled' => 'Required Hours',
        'worked'    => 'Worked',
        'remaining' => 'Shortfall Hours',
        'break'     => 'Break',
        'estimated' => 'Estimated',
        'source'    => 'Source',
    ],

    'summary' => [
        'total-for'         => 'Total — :employee',
        'days-count'        => ':count days',
        'missing-checkouts' => ':count missing check-outs',
    ],

    'notification' => [
        'completed' => ':count rows exported.',
        'failed'    => ':count rows failed.',
    ],
];
