<?php

return [
    'title' => 'Attendance Event',

    'navigation' => [
        'title' => 'Attendance Events',
    ],

    'table' => [
        'columns' => [
            'employee'    => 'Employee',
            'punched-at'  => 'Punch Time',
            'direction'   => 'Direction',
            'source'      => 'Source',
            'linked-row'  => 'Linked Row',
        ],

        'directions' => [
            'unknown' => 'Unknown',
            'in'      => 'In',
            'out'     => 'Out',
        ],

        'filters' => [
            'employee'   => 'Employee',
            'source'     => 'Source',
            'punched-at' => 'Punch Date',
            'date-from'  => 'From',
            'date-to'    => 'To',
        ],

        'toolbar-actions' => [
            'export' => 'Export',
        ],
    ],
];
