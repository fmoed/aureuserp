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
            'linked-row'  => 'Linked record',
            'linked-hint' => 'Empty means awaiting auto-pairing. Fix times in Attendance, not here.',
        ],

        'directions' => [
            'unknown' => 'Unknown',
            'in'      => 'In',
            'out'     => 'Out',
        ],

        'filters' => [
            'linkage'           => 'Linkage',
            'linkage-paired'    => 'Paired',
            'linkage-unpaired'  => 'Unpaired',
            'employee'          => 'Employee',
            'source'            => 'Source',
            'punched-at'        => 'Punch Date',
            'date-from'         => 'From',
            'date-to'           => 'To',
            'range-too-wide'    => 'The range must not exceed :days days.',
        ],

        'toolbar-actions' => [
            'export' => 'Export',
        ],
    ],
];
