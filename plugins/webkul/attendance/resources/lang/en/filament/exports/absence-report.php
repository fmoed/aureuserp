<?php

return [
    'columns' => [
        'employee'             => 'Employee',
        'workdays'             => 'Total Workdays',
        'present'              => 'Present Days',
        'absent'               => 'Absent Days',
        'absence-rate'         => 'Absence rate',
        'on-leave'             => 'On Leave Days',
        'holidays'             => 'Public Holidays',
        'pending'              => 'Pending Leave Days',
        'minutes'              => 'Worked Minutes',
        'scheduled-hours'      => 'Scheduled Hours',
        'regular-hours'        => 'Regular Hours',
        'overtime-hours'       => 'Overtime Hours',
        'break-hours'          => 'Break Hours',
        'late-minutes'         => 'Late Minutes',
        'early-leave-minutes'  => 'Early Leave Minutes',
        'variance-hours'       => 'Variance Hours',
    ],

    'options' => [
        'employee'   => 'Employee',
        'department' => 'Department',
        'calendar'   => 'Work schedule',
        'date-from'  => 'From',
        'date-to'    => 'To',
    ],

    'notification' => [
        'completed' => ':count rows exported.',
        'failed'    => ':count rows failed.',
    ],
];
