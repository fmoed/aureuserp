<?php

return [
    'title' => 'Festivos',

    'scopes' => [
        'company' => 'Toda la empresa',
        'shift'   => 'Específico del turno',
    ],

    'table' => [
        'columns' => [
            'name'      => 'Festivo',
            'scope'     => 'Ámbito',
            'date-from' => 'Desde',
            'date-to'   => 'Hasta',
        ],
    ],

    'actions' => [
        'create' => [
            'label' => 'Añadir festivo al turno',
        ],

        'assign-existing' => [
            'label'     => 'Aplicar un festivo existente',
            'holiday'   => 'Festivo de empresa',
            'assigned'  => 'Festivo aplicado a este turno.',
        ],
    ],
];
