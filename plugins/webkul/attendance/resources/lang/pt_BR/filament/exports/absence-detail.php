<?php

return [
    'columns' => [
        'employee'              => 'Funcionário',
        'schedule'              => 'Escala',
        'workdays'              => 'Dias úteis',
        'present'               => 'Presente',
        'absent'                => 'Ausente',
        'on-leave'              => 'Licenças',
        'hours'                 => 'Horas trabalhadas',
        'scheduled-minutes'     => 'Horas programadas',
        'worked-minutes'        => 'Horas trabalhadas',
        'overtime-minutes'      => 'Horas extras',
        'break-minutes'         => 'Horas de intervalo',
        'late-minutes'          => 'Minutos de atraso',
        'early-leave-minutes'   => 'Minutos de saída antecipada',
        'variance-minutes'      => 'Variação de horas',
        'absence-rate'          => 'Taxa de ausência',
    ],

    'notification' => [
        'completed'   => ':count linhas exportadas.',
        'failed'      => ':count linhas com falha.',
        'cap-title'   => 'Exportação muito grande',
        'cap-reached' => 'A solicitação gerou :count linhas, acima do limite de :limit linhas. Reduza o intervalo de datas ou filtre por um funcionário.',
    ],
];
