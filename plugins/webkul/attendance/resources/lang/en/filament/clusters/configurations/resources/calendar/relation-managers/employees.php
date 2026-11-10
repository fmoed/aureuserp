<?php

return [
    'title' => 'Assigned employees',

    'default-shift-summary' => 'This is the default shift: :direct assigned directly | :inherited included automatically (no shift set) | actual total: :total — any employee without a selected shift is counted in this shift automatically.',
    'direct-shift-summary'  => ':direct employees are assigned directly to this shift.',

    'columns' => [
        'name'       => 'Employee',
        'barcode'    => 'Badge / barcode',
        'department' => 'Department',
        'job'        => 'Job position',
        'time-zone'  => 'Time zone',
    ],

    'actions' => [
        'unassign' => [
            'label'       => 'Remove from shift',
            'description' => 'This employee will fall back to the company default shift.',
            'success'     => ':employee was removed from this shift.',
        ],
    ],
];
