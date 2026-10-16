<?php

return [
    'title' => 'Présences',

    'navigation' => [
        'title' => 'Registres de présence',
    ],

    'form' => [
        'employee'                   => 'Employé',
        'work-date'                  => 'Date de travail',
        'work-date-hint'             => 'Un relevé manuel par jour. Employé et date verrouillés après enregistrement.',
        'duplicate-day'              => ':employee a déjà un relevé manuel le :date. Modifiez-le plutôt.',
        'check-in'                   => 'Arrivée',
        'check-in-not-in-work-date'  => "L'arrivée doit être dans la date de travail.",
        'clock-hint'                 => 'Heures en :zone.',
        'check-out'                  => 'Départ',
        'check-out-before-check-in'  => 'Le départ doit être après l’arrivée.',
        'check-out-not-in-work-date' => 'Le départ doit être dans les 16 heures suivant l’arrivée.',
        'checkout-hint'              => 'Termine la ligne ouverte, fuseau de l’employé, sous 16 heures.',
        'long-shift-warning'         => 'Attention : ce poste dure :hours heures.',
        'source'                     => 'Source',
        'source-manual'              => 'Manuel',
    ],

    'table' => [
        'columns' => [
            'employee'   => 'Employé',
            'work-date'  => 'Date de travail',
            'check-in'   => 'Arrivée',
            'check-out'  => 'Départ',
            'worked'     => 'Travaillé',
            'source'     => 'Source',
            'created-at' => 'Créé le',
        ],

        'filters' => [
            'employee'          => 'Employé',
            'source'            => 'Source',
            'work-date'         => 'Date de travail',
            'preset'            => 'Période rapide',
            'preset-hint'       => 'Choisir une période rapide ignore Du/Au.',
            'preset-today'      => 'Aujourd’hui',
            'preset-this-week'  => 'Cette semaine',
            'preset-this-month' => 'Ce mois-ci',
            'date-from'         => 'Du',
            'date-to'           => 'Au',
            'timezone-tip'      => 'Heure de l’employé (:zone)',
        ],

        'toolbar-actions' => [
            'export' => 'Exporter',
        ],

        'record-actions' => [
            'add-checkout' => 'Ajouter le départ',
        ],
    ],
];
