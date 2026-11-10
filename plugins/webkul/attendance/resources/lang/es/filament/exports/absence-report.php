<?php

return [
    'columns' => [
        'employee'             => 'Empleado',
        'workdays'             => 'Días laborables totales',
        'present'              => 'Días presentes',
        'absent'               => 'Días ausentes',
        'absence-rate'         => 'Tasa de ausencias',
        'on-leave'             => 'Días de permiso',
        'holidays'             => 'Días festivos',
        'pending'              => 'Permisos pendientes',
        'minutes'              => 'Minutos trabajados',
        'scheduled-hours'      => 'Horas programadas',
        'regular-hours'        => 'Horas regulares',
        'overtime-hours'       => 'Horas extra',
        'break-hours'          => 'Horas de descanso',
        'late-minutes'         => 'Minutos de retraso',
        'early-leave-minutes'  => 'Minutos de salida anticipada',
        'variance-hours'       => 'Variación de horas',
    ],

    'options' => [
        'department' => 'Departamento',
        'calendar'   => 'Horario de trabajo',
        'employee'   => 'Empleado',
        'date-from'  => 'Desde',
        'date-to'    => 'Hasta',
    ],

    'notification' => [
        'completed' => ':count filas exportadas.',
        'failed'    => ':count filas fallidas.',
    ],
];
