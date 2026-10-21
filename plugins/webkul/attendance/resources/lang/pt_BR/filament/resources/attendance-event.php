<?php

return [
    'title' => 'Eventos de presença',

    'navigation' => [
        'title' => 'Eventos de presença',
    ],

    'table' => [
        'columns' => [
            'employee'    => 'Funcionário',
            'punched-at'  => 'Hora da marcação',
            'direction'   => 'Direção',
            'source'      => 'Fonte',
            'linked-row'  => 'Linha vinculada',
        ],

        'directions' => [
            'unknown' => 'Desconhecido',
            'in'      => 'Entrada',
            'out'     => 'Saída',
        ],

        'filters' => [
            'employee'   => 'Funcionário',
            'source'     => 'Fonte',
            'punched-at' => 'Data da marcação',
            'date-from'  => 'De',
            'date-to'    => 'Até',
        ],

        'toolbar-actions' => [
            'export' => 'Exportar',
        ],
    ],
];
