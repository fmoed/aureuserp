<?php

return [
    'title' => 'Employés affectés',

    'default-shift-summary' => 'Ceci est le poste par défaut : :direct affectés directement | :inherited inclus automatiquement (sans poste) | total réel : :total — tout employé sans poste est compté automatiquement dans ce poste.',
    'direct-shift-summary'  => ':direct employés sont affectés directement à ce poste.',

    'columns' => [
        'name'       => 'Employé',
        'barcode'    => 'Code-barres',
        'department' => 'Département',
        'job'        => 'Poste',
        'time-zone'  => 'Fuseau horaire',
    ],

    'actions' => [
        'unassign' => [
            'label'       => 'Retirer du poste',
            'description' => 'Cet employé reviendra au poste par défaut de l’entreprise.',
            'success'     => ':employee a été retiré de ce poste.',
        ],
    ],
];
