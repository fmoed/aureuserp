<?php

return [
    'title' => 'Registro de presença',

    'navigation' => [
        'title' => 'Registro de presença',
    ],

    'form' => [
        'heading'        => 'Filtros do registro',
        'employee'       => 'Funcionário',
        'all-employees'  => 'Todos os funcionários',
        'view-mode'      => 'Intervalo de tempo',
        'mode-month'     => 'Mês',
        'mode-range'     => 'Período personalizado',
        'mode-year'      => 'Ano',
        'month'          => 'Mês',
        'year'           => 'Ano',
        'year-hint'      => 'Dados dos últimos dois anos.',
        'date-from'      => 'De',
        'date-to'        => 'Até',
        'range-hint'     => 'Máximo de :days dias.',
        'apply'          => 'Aplicar',
        'reset'          => 'Limpar',
        'range-too-wide' => 'O intervalo não deve exceder :days dias.',
        'month-future'   => 'O mês selecionado está no futuro.',
    ],

    'filter-chips' => [
        'period'   => 'Período',
        'employee' => 'Funcionário',
        'clear'    => 'Limpar',
    ],

    'table' => [
        'columns' => [
            'employee' => 'Funcionário',
            'schedule' => 'Escala de trabalho',
            'rate'     => 'Taxa de ausências',
            'workdays' => 'Dias úteis',
        ],

        'empty' => 'Sem dias úteis neste intervalo. Funcionários sem escala ficam ocultos.',

        'view' => [
            'totals' => 'Resumo por funcionário',
            'days'   => 'Registro diário',
        ],
    ],

    'statuses' => [
        'short' => [
            'present'    => 'P',
            'absent'     => 'A',
            'on-leave'   => 'L',
            'half-day'   => '½',
            'pending'    => '?',
            'holiday'    => 'H',
            'weekly-off' => 'W',
            'future'     => '',
        ],
        'present'    => 'Presente',
        'absent'     => 'Ausente',
        'on-leave'   => 'De licença',
        'pending'    => 'Pendente',
        'holiday'    => 'Feriado',
        'half-day'   => 'Meio dia',
        'weekly-off' => 'Folga',
        'future'     => 'Próximo',
    ],

    'empty' => [
        'heading' => 'Sem dias úteis para mostrar.',
    ],

    'grid' => [
        'summary'      => 'Resumo',
        'pending-note' => 'A licença pendente conta como ausência até aprovação.',
        'chart'        => 'Presentes e ausentes por dia',
        'legend'       => 'Guia de status',
        'totals'       => [
            'employees' => 'Funcionários',
            'present'   => 'Dias presentes',
            'absent'    => 'Dias ausentes',
            'on-leave'  => 'Dias de licença',
            'pending'   => 'Pendente',
            'holidays'  => 'Feriados',
            'rate'      => 'Taxa de ausências',
        ],
    ],

    'export' => 'Resumo de ausências (uma linha por funcionário)',

    'export-detail' => 'Detalhe diário (uma linha por dia útil)',
];
