<?php

return [
    'columns' => [
        'employee'     => 'Employé',
        'workdays'     => 'Jours ouvrés totaux',
        'present'      => 'Jours présents',
        'absent'       => 'Jours absents',
        'absence-rate' => 'Absence %',
    ],

    'options' => [
        'employee'  => 'Employé',
        'date-from' => 'Du',
        'date-to'   => 'Au',
    ],

    'notification' => [
        'completed' => ':count lignes exportées.',
        'failed'    => ':count lignes en échec.',
    ],
];
