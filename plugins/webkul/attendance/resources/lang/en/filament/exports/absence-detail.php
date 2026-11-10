<?php

return [
    'columns' => [
        'employee'              => 'Employee',
        'schedule'              => 'Schedule',
        'workdays'              => 'Workdays',
        'present'               => 'Present',
        'absent'                => 'Absent',
        'on-leave'              => 'Leaves',
        'hours'                 => 'Working Hours',
        'scheduled-minutes'     => 'Scheduled Hours',
        'worked-minutes'        => 'Worked Hours',
        'overtime-minutes'      => 'Overtime Hours',
        'break-minutes'         => 'Break Hours',
        'late-minutes'          => 'Late Minutes',
        'early-leave-minutes'   => 'Early Leave Minutes',
        'variance-minutes'      => 'Variance Hours',
        'absence-rate'          => 'Absence Rate',
    ],

    'notification' => [
        'completed'   => ':count rows exported.',
        'failed'      => ':count rows failed.',
        'cap-title'   => 'Export too large',
        'cap-reached' => 'The request produced :count rows, above the :limit row limit. Narrow the date range or filter to one employee.',
    ],
];
