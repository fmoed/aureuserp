<?php

return [
    'title'      => 'Horaire de travail (poste)',
    'navigation' => [
        'title' => 'Horaires de travail',
    ],

    'actions' => [
        'assign-employees' => [
            'label'           => 'Affecter des employés à ce poste',
            'department'      => 'Département (facultatif)',
            'department-hint' => 'Applique le poste à tous les employés de ce département.',
            'employees'       => 'Employés (facultatif)',
            'employees-hint'  => 'Les employés sélectionnés manuellement remplacent les filtres département et poste.',
            'job'             => 'Poste (facultatif)',
            'job-hint'        => 'Limite l’affectation aux employés occupant ce poste.',
            'preview-label'   => 'Employés à mettre à jour',
            'preview-count'   => ':count employés correspondent à la sélection actuelle.',
            'select-required' => 'Choisissez un département, un poste ou au moins un employé.',
            'assigned'        => ':count employés affectés à ce poste.',
        ],
    ],

    'table' => [
        'columns' => [
            'employees-count'         => 'Employés affectés',
            'employees-count-tooltip' => 'Poste par défaut : :direct affectés directement, :inherited inclus automatiquement (sans poste).',
        ],
    ],
];
