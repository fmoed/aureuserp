<?php

return [
    'columns' => [
        'employee'              => 'Empleado',
        'schedule'              => 'Horario',
        'workdays'              => 'Días laborables',
        'present'               => 'Presente',
        'absent'                => 'Ausente',
        'on-leave'              => 'Permisos',
        'hours'                 => 'Horas trabajadas',
        'scheduled-minutes'     => 'Horas programadas',
        'worked-minutes'        => 'Horas trabajadas',
        'overtime-minutes'      => 'Horas extra',
        'break-minutes'         => 'Horas de descanso',
        'late-minutes'          => 'Minutos de retraso',
        'early-leave-minutes'   => 'Minutos de salida anticipada',
        'variance-minutes'      => 'Variación de horas',
        'absence-rate'          => 'Tasa de ausencia',
    ],

    'notification' => [
        'completed'   => ':count filas exportadas.',
        'failed'      => ':count filas fallidas.',
        'cap-title'   => 'Exportación demasiado grande',
        'cap-reached' => 'La solicitud produjo :count filas, por encima del límite de :limit filas. Reduzca el rango de fechas o filtre por un empleado.',
    ],
];
