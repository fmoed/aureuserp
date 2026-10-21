<?php

return [
    'title' => 'Aperçu des présences',

    'navigation' => [
        'title' => 'Aperçu des présences',
    ],

    'form' => [
        'employee'       => 'Employé',
        'all-employees'  => 'Tous les employés',
        'date-from'      => 'Du',
        'date-to'        => 'Au',
        'range-too-wide' => 'La plage ne doit pas dépasser :days jours.',
    ],

    'tabs' => [
        'daily'   => 'Quotidien',
        'monthly' => 'Mensuel',
    ],

    'table' => [
        'columns' => [
            'date'      => 'Date',
            'employee'  => 'Employé',
            'status'    => 'Statut',
            'check-in'  => 'Arrivée',
            'check-out' => 'Départ',
            'minutes'   => 'Travaillé',
        ],

        'empty' => 'Aucun jour ouvré dans cette plage.',
    ],

    'statuses' => [
        'present'  => 'Présent',
        'absent'   => 'Absent',
        'pending'  => 'En attente',
        'half-day' => 'Demi-journée',
    ],

    'grid' => [
        'legend' => 'A absent · L en congé · P en attente · H férié · vide présent.',
    ],

    'export' => 'Rapport d’absence',
];
