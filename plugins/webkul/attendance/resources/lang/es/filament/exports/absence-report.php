<?php

return [
    'columns' => [
        'employee'     => 'Empleado',
        'workdays'     => 'Días laborables totales',
        'present'      => 'Días presentes',
        'absent'       => 'Días ausentes',
        'absence-rate' => 'Tasa de ausencias',
        'on-leave'     => 'Días de permiso',
        'holidays'     => 'Días festivos',
        'pending'      => 'Permisos pendientes',
        'minutes'      => 'Minutos trabajados',
    ],

    'options' => [
        'employee'  => 'Empleado',
        'date-from' => 'Desde',
        'date-to'   => 'Hasta',
    ],

    'notification' => [
        'completed' => ':count filas exportadas.',
        'failed'    => ':count filas fallidas.',
    ],
];
