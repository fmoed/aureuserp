<?php

return [
    'columns' => [
        'employee'     => 'Employé',
        'workdays'     => 'Jours ouvrés totaux',
        'present'      => 'Jours présents',
        'absent'       => 'Jours absents',
        'absence-rate' => "Taux d'absentéisme",
        'on-leave'     => 'Jours de congé',
        'holidays'     => 'Jours fériés',
        'pending'      => 'Congés en attente',
        'minutes'      => 'Minutes travaillées',
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
