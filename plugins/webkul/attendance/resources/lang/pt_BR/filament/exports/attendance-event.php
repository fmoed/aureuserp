<?php

return [
    'columns' => [
        'employee'   => 'Funcionário',
        'punched-at' => 'Hora da marcação',
        'direction'  => 'Direção',
        'source'     => 'Fonte',
        'linked-row' => 'Linha vinculada',
        'pushed'     => 'Status',
    ],

    'pushed' => [
        'paired'  => 'Emparelhado',
        'pending' => 'Pendente',
    ],

    'notification' => [
        'completed' => ':count linhas exportadas.',
        'failed'    => ':count linhas com falha.',
    ],
];
