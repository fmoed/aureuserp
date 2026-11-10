<?php

return [
    'title' => 'Batidas',

    'table' => [
        'columns' => [
            'user-id'    => 'ID do usuário no dispositivo',
            'employee'   => 'Funcionário',
            'unmatched'  => 'Sem correspondência',
            'punch-time' => 'Hora da batida',
            'raw-type'   => 'Tipo bruto',
            'pushed-at'  => 'Enviado em',
            'pending'    => 'Pendente',
        ],

        'filters' => [
            'match-status' => 'Correspondência',
            'unmatched'    => 'Batidas sem correspondência',
            'matched'      => 'Batidas correspondentes',
            'push-status'  => 'Status de envio',
            'pushed'       => 'Enviado',
        ],

        'empty' => 'Nenhuma batida registrada para este dispositivo ainda.',
    ],

    'actions' => [
        'rematch' => [
            'label'     => 'Recorrelacionar batidas pendentes',
            'completed' => ':count batidas associadas a funcionários.',
        ],

        'assign' => [
            'label'     => 'Atribuir a funcionário',
            'employee'  => 'Funcionário',
            'assigned'  => 'Código de barras salvo para :name e :count batidas históricas recuperadas.',
        ],
    ],
];
