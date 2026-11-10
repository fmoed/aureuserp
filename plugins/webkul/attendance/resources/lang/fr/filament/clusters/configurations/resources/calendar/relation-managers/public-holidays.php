<?php

return [
    'title' => 'Jours fériés',

    'scopes' => [
        'company' => 'Toute la société',
        'shift'   => 'Spécifique au poste',
    ],

    'table' => [
        'columns' => [
            'name'      => 'Jour férié',
            'scope'     => 'Portée',
            'date-from' => 'Du',
            'date-to'   => 'Au',
        ],
    ],

    'actions' => [
        'create' => [
            'label' => 'Ajouter un jour férié au poste',
        ],

        'assign-existing' => [
            'label'     => 'Appliquer un jour férié existant',
            'holiday'   => 'Jour férié société',
            'assigned'  => 'Jour férié appliqué à ce poste.',
        ],
    ],
];
