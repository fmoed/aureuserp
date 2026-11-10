<?php

return [
    'title' => 'Public Holidays',

    'scopes' => [
        'company' => 'Company-wide',
        'shift'   => 'Shift-specific',
    ],

    'table' => [
        'columns' => [
            'name'      => 'Holiday',
            'scope'     => 'Scope',
            'date-from' => 'From',
            'date-to'   => 'To',
        ],
    ],

    'actions' => [
        'create' => [
            'label' => 'Add shift holiday',
        ],

        'assign-existing' => [
            'label'     => 'Apply an existing holiday',
            'holiday'   => 'Company holiday',
            'assigned'  => 'Holiday applied to this shift.',
        ],
    ],
];
