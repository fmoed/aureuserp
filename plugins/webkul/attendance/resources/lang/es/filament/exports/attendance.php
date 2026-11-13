<?php

return [
    'columns' => [
        'employee'  => 'Empleado',
        'work-date' => 'Fecha de trabajo',
        'check-in'  => 'Entrada',
        'check-out' => 'Salida',
        'scheduled' => 'Horas requeridas',
        'worked'    => 'Trabajado',
        'remaining' => 'Horas faltantes',
        'break'     => 'Descanso',
        'estimated' => 'Estimado',
        'source'    => 'Fuente',
    ],

    'summary' => [
        'total-for'         => 'Total — :employee',
        'days-count'        => ':count días',
        'missing-checkouts' => ':count sin salida',
    ],

    'notification' => [
        'completed' => ':count filas exportadas.',
        'failed'    => ':count filas fallidas.',
    ],
];
