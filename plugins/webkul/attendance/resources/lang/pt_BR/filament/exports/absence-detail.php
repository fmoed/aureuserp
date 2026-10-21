<?php

return [
    'columns' => [
        'employee' => 'Funcionário',
        'date'     => 'Data',
        'day'      => 'Dia',
        'status'   => 'Status',
        'minutes'  => 'Minutos',
    ],

    'notification' => [
        'completed'   => ':count linhas exportadas.',
        'failed'      => ':count linhas com falha.',
        'cap-title'   => 'Exportação muito grande',
        'cap-reached' => 'A solicitação gerou :count linhas, acima do limite de :limit linhas. Reduza o intervalo de datas ou filtre por um funcionário.',
    ],
];
