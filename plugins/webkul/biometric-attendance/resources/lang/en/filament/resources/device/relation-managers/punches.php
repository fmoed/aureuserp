<?php

return [
    'title' => 'Punches',

    'table' => [
        'columns' => [
            'user-id'    => 'Device user ID',
            'employee'   => 'Employee',
            'unmatched'  => 'Unmatched',
            'punch-time' => 'Punch time',
            'raw-type'   => 'Raw type',
            'pushed-at'  => 'Pushed at',
            'pending'    => 'Pending',
        ],

        'filters' => [
            'match-status'  => 'Matching',
            'unmatched'     => 'Unmatched punches',
            'matched'       => 'Matched punches',
            'push-status'   => 'Push status',
            'pushed'        => 'Pushed',
        ],

        'empty' => 'No punches recorded for this device yet.',
    ],

    'actions' => [
        'rematch' => [
            'label'     => 'Re-match unmatched punches',
            'completed' => ':count punches matched to employees.',
        ],

        'assign' => [
            'label'     => 'Assign to employee',
            'employee'  => 'Employee',
            'assigned'  => 'Barcode saved for :name and :count historical punches recovered.',
            'conflict'  => 'Barcode :barcode is already assigned to :name in this company.',
        ],
    ],
];
