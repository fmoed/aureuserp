<?php

return [
    'columns' => [
        'employee'     => 'Employee',
        'workdays'     => 'Total Workdays',
        'present'      => 'Present Days',
        'absent'       => 'Absent Days',
        'absence-rate' => 'Absence rate',
        'on-leave'     => 'On Leave Days',
        'holidays'     => 'Public Holidays',
        'pending'      => 'Pending Leave Days',
        'minutes'      => 'Worked Minutes',
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
