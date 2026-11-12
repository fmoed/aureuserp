<?php

return [
    'columns' => [
        'employee'  => 'Funcionário',
        'work-date' => 'Data de trabalho',
        'check-in'  => 'Entrada',
        'check-out' => 'Saída',
        'scheduled' => 'Horas exigidas',
        'worked'    => 'Trabalhado',
        'remaining' => 'Horas faltantes',
        'break'     => 'Intervalo',
        'estimated' => 'Estimado',
        'source'    => 'Fonte',
    ],

    'summary' => [
        'total-for'         => 'Total — :employee',
        'days-count'        => ':count dias',
        'missing-checkouts' => ':count sem saída',
    ],

    'notification' => [
        'completed' => ':count linhas exportadas.',
        'failed'    => ':count linhas com falha.',
    ],
];
