<?php

return [
    'columns' => [
        'employee'     => 'Funcionário',
        'workdays'     => 'Dias úteis totais',
        'present'      => 'Dias presentes',
        'absent'       => 'Dias ausentes',
        'absence-rate' => 'Ausência %',
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
