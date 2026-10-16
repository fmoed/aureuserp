<?php

return [
    'title' => 'Événements de présence',

    'navigation' => [
        'title' => 'Événements de présence',
    ],

    'table' => [
        'columns' => [
            'employee'    => 'Employé',
            'punched-at'  => 'Heure de pointage',
            'direction'   => 'Direction',
            'source'      => 'Source',
            'linked-row'  => 'Enregistrement lié',
            'linked-hint' => 'Vide = en attente de liaison auto. Corrigez dans Présences.',
        ],

        'directions' => [
            'unknown' => 'Inconnu',
            'in'      => 'Entrée',
            'out'     => 'Sortie',
        ],

        'filters' => [
            'employee'       => 'Employé',
            'source'         => 'Source',
            'punched-at'     => 'Date de pointage',
            'date-from'      => 'Du',
            'date-to'        => 'Au',
            'range-too-wide' => 'La plage ne doit pas dépasser :days jours.',
        ],

        'toolbar-actions' => [
            'export' => 'Exporter',
        ],
    ],
];
