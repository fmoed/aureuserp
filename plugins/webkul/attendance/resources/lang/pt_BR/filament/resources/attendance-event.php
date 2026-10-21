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
            'linked-row'  => 'Registro vinculado',
            'linked-hint' => 'Vazio = aguardando vínculo. Corrija em Presenças.',
        ],

        'directions' => [
            'unknown' => 'Desconhecido',
            'in'      => 'Entrada',
            'out'     => 'Saída',
        ],

        'filters' => [
            'linkage'           => 'Vínculo',
            'linkage-paired'    => 'Vinculado',
            'linkage-unpaired'  => 'Não vinculado',
            'employee'          => 'Funcionário',
            'source'            => 'Fonte',
            'punched-at'        => 'Data da marcação',
            'date-from'         => 'De',
            'date-to'           => 'Até',
            'range-too-wide'    => 'O intervalo não deve exceder :days dias.',
        ],

        'toolbar-actions' => [
            'export' => 'Exportar',
        ],
    ],
];
