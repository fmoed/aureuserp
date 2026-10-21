<?php

return [
    'columns' => [
        'employee'     => 'Employee',
        'workdays'     => 'Total Workdays',
        'present'      => 'Present Days',
        'absent'       => 'Absent Days',
        'absence-rate' => 'Absence %',
    ],

    'options' => [
        'employee'  => 'Employee',
        'date-from' => 'From',
        'date-to'   => 'To',
    ],

    'notification' => [
        'completed' => ':count rows exported.',
        'failed'    => ':count rows failed.',
    ],
];
