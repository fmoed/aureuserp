<?php

return [
    'columns' => [
        'employee'             => 'Funcionário',
        'workdays'             => 'Dias úteis totais',
        'present'              => 'Dias presentes',
        'absent'               => 'Dias ausentes',
        'absence-rate'         => 'Taxa de ausências',
        'on-leave'             => 'Dias de licença',
        'holidays'             => 'Feriados',
        'pending'              => 'Licenças pendentes',
        'minutes'              => 'Minutos trabalhados',
        'scheduled-hours'      => 'Horas programadas',
        'regular-hours'        => 'Horas regulares',
        'overtime-hours'       => 'Horas extras',
        'break-hours'          => 'Horas de intervalo',
        'late-minutes'         => 'Minutos de atraso',
        'early-leave-minutes'  => 'Minutos de saída antecipada',
        'variance-hours'       => 'Variação de horas',
    ],

    'options' => [
        'department' => 'Departamento',
        'calendar'   => 'Jornada de trabalho',
        'employee'   => 'Funcionário',
        'date-from'  => 'De',
        'date-to'    => 'Até',
    ],

    'notification' => [
        'completed' => ':count linhas exportadas.',
        'failed'    => ':count linhas com falha.',
    ],
];
