<?php

return [
    'title' => 'Resumo de presença',

    'navigation' => [
        'title' => 'Resumo de presença',
    ],

    'form' => [
        'heading'        => 'Filtros',
        'employee'       => 'Funcionário',
        'all-employees'  => 'Todos os funcionários',
        'date-from'      => 'De',
        'date-to'        => 'Até',
        'apply'          => 'Aplicar',
        'range-too-wide' => 'O intervalo não deve exceder :days dias.',
    ],

    'tabs' => [
        'daily'   => 'Diária',
        'monthly' => 'Mensal',
    ],

    'table' => [
        'columns' => [
            'date'      => 'Data',
            'employee'  => 'Funcionário',
            'status'    => 'Status',
            'check-in'  => 'Entrada',
            'check-out' => 'Saída',
            'minutes'   => 'Trabalhado',
        ],

        'empty' => 'Sem dias úteis neste intervalo.',
    ],

    'statuses' => [
        'present'  => 'Presente',
        'absent'   => 'Ausente',
        'pending'  => 'Pendente',
        'half-day' => 'Meio dia',
    ],

    'empty' => [
        'heading' => 'Sem dias úteis para mostrar.',
    ],

    'grid' => [
        'legend' => 'A ausente · L de licença · P pendente · H feriado · vazio presente.',
    ],

    'export' => 'Relatório de ausências',
];
