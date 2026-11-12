<?php

return [
    'columns' => [
        'employee'  => 'Employé',
        'work-date' => 'Date de travail',
        'check-in'  => 'Arrivée',
        'check-out' => 'Départ',
        'scheduled' => 'Heures requises',
        'worked'    => 'Travaillé',
        'remaining' => 'Heures manquantes',
        'break'     => 'Pause',
        'estimated' => 'Estimé',
        'source'    => 'Source',
    ],

    'summary' => [
        'total-for'         => 'Total — :employee',
        'days-count'        => ':count jours',
        'missing-checkouts' => ':count sans sortie',
    ],

    'notification' => [
        'completed' => ':count lignes exportées.',
        'failed'    => ':count lignes en échec.',
    ],
];
