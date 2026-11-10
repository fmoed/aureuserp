<?php

return [
    'title' => 'Registre de présence',

    'navigation' => [
        'title' => 'Registre de présence',
    ],

    'form' => [
        'department'      => 'Département',
        'all-departments' => 'Tous les départements',
        'calendar'        => 'Horaire de travail',
        'all-calendars'   => 'Tous les horaires',
        'heading'         => 'Filtres du registre',
        'employee'        => 'Employé',
        'all-employees'   => 'Tous les employés',
        'view-mode'       => 'Plage temporelle',
        'mode-month'      => 'Mois',
        'mode-range'      => 'Période personnalisée',
        'mode-year'       => 'Année',
        'month'           => 'Mois',
        'year'            => 'Année',
        'year-hint'       => 'Données des deux dernières années.',
        'date-from'       => 'Du',
        'date-to'         => 'Au',
        'range-hint'      => 'Maximum :days jours.',
        'apply'           => 'Appliquer',
        'reset'           => 'Réinitialiser',
        'range-too-wide'  => 'La plage ne doit pas dépasser :days jours.',
        'month-future'    => 'Le mois sélectionné est dans le futur.',
    ],

    'filter-chips' => [
        'department' => 'Département',
        'calendar'   => 'Horaire de travail',
        'period'     => 'Période',
        'employee'   => 'Employé',
        'clear'      => 'Effacer',
    ],

    'table' => [
        'columns' => [
            'employee'          => 'Employé',
            'schedule'          => 'Planning de travail',
            'rate'              => "Taux d'absentéisme",
            'workdays'          => 'Jours ouvrés',
            'scheduled-hours'   => 'Heures planifiées',
            'worked-hours'      => 'Heures travaillées',
            'overtime-hours'    => 'Heures supplémentaires',
            'late-minutes'      => 'Retard (min)',
            'variance-hours'    => "Écart d'heures",
            'missing-checkouts' => 'Sorties manquantes',
            'break'             => 'Pause',
            'late'              => 'Retard',
            'early-leave'       => 'Départ anticipé',
            'estimated'         => 'Estimé',
        ],

        'empty' => 'Aucun jour ouvré dans cette plage. Les employés sans planning sont masqués.',

        'view' => [
            'totals' => 'Résumé par employé',
            'days'   => 'Registre quotidien',
        ],
    ],

    'statuses' => [
        'short' => [
            'present'          => 'P',
            'absent'           => 'A',
            'on-leave'         => 'L',
            'half-day'         => '½',
            'pending'          => '?',
            'holiday'          => 'H',
            'weekly-off'       => 'W',
            'missing-checkout' => '!',
            'weekend-present'  => 'W+',
            'holiday-present'  => 'H+',
            'future'           => '',
        ],
        'present'          => 'Présent',
        'absent'           => 'Absent',
        'on-leave'         => 'En congé',
        'pending'          => 'En attente',
        'holiday'          => 'Jour férié',
        'half-day'         => 'Demi-journée',
        'weekly-off'       => 'Repos',
        'missing-checkout' => 'Sortie manquante',
        'weekend-present'  => 'Travail le repos',
        'holiday-present'  => 'Travail un jour férié',
        'future'           => 'À venir',
    ],

    'empty' => [
        'heading' => 'Aucun jour ouvré à afficher.',
    ],

    'grid' => [
        'summary'      => 'Résumé',
        'pending-note' => 'Le congé en attente compte comme absence jusqu’à approbation.',
        'chart'        => 'Présents et absents par jour',
        'legend'       => 'Guide des statuts',
        'totals'       => [
            'employees' => 'Employés',
            'present'   => 'Jours présents',
            'absent'    => 'Jours absents',
            'on-leave'  => 'Jours de congé',
            'pending'   => 'En attente',
            'holidays'  => 'Jours fériés',
            'rate'      => "Taux d'absentéisme",
        ],
    ],

    'export' => 'Synthèse des absences (une ligne par employé)',

    'export-detail' => 'Détail quotidien (une ligne par jour ouvré)',
];
