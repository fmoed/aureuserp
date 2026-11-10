<?php

return [
    'title' => 'Feriados',

    'scopes' => [
        'company' => 'Toda a empresa',
        'shift'   => 'Específico do turno',
    ],

    'table' => [
        'columns' => [
            'name'      => 'Feriado',
            'scope'     => 'Escopo',
            'date-from' => 'De',
            'date-to'   => 'Até',
        ],
    ],

    'actions' => [
        'create' => [
            'label' => 'Adicionar feriado ao turno',
        ],

        'assign-existing' => [
            'label'     => 'Aplicar feriado existente',
            'holiday'   => 'Feriado da empresa',
            'assigned'  => 'Feriado aplicado a este turno.',
        ],
    ],
];
