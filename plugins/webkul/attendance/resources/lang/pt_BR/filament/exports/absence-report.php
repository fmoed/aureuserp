<?php

return [
    'columns' => [
        'employee'     => 'Funcionário',
        'workdays'     => 'Dias úteis totais',
        'present'      => 'Dias presentes',
        'absent'       => 'Dias ausentes',
        'absence-rate' => 'Taxa de ausências',
        'on-leave'     => 'Dias de licença',
        'holidays'     => 'Feriados',
        'pending'      => 'Licenças pendentes',
        'minutes'      => 'Minutos trabalhados',
    ],

    'options' => [
        'employee'  => 'Funcionário',
        'date-from' => 'De',
        'date-to'   => 'Até',
    ],

    'notification' => [
        'completed' => ':count linhas exportadas.',
        'failed'    => ':count linhas com falha.',
    ],
];
