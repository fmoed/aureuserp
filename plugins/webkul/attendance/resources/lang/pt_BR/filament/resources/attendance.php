<?php

return [
    'title' => 'Presenças',

    'navigation' => [
        'title' => 'Registros de presença',
    ],

    'form' => [
        'employee'                   => 'Funcionário',
        'work-date'                  => 'Data de trabalho',
        'work-date-hint'             => 'Um registro manual por dia. Funcionário e data travam ao salvar.',
        'duplicate-day'              => ':employee já tem um registro manual em :date. Edite-o.',
        'check-in'                   => 'Entrada',
        'check-in-not-in-work-date'  => 'A entrada deve estar dentro da data de trabalho.',
        'clock-hint'                 => 'Horas em :zone.',
        'check-out'                  => 'Saída',
        'check-out-before-check-in'  => 'A saída deve ser após a entrada.',
        'check-out-not-in-work-date' => 'A saída deve estar dentro de :hours horas após a entrada.',
        'checkout-hint'              => 'Conclui a linha aberta, no fuso do funcionário, em até :hours horas.',
        'long-shift-warning'         => 'Atenção: este turno dura :hours horas.',
        'source'                     => 'Fonte',
        'source-manual'              => 'Manual',
    ],

    'table' => [
        'columns' => [
            'employee'   => 'Funcionário',
            'work-date'  => 'Data de trabalho',
            'check-in'   => 'Entrada',
            'check-out'  => 'Saída',
            'next-day'   => 'Noturno',
            'estimated'  => 'Estimado',
            'worked'     => 'Trabalhado',
            'source'     => 'Fonte',
            'created-at' => 'Criado em',
        ],

        'filters' => [
            'department'        => 'Departamento',
            'calendar'          => 'Jornada de trabalho',
            'employee'          => 'Funcionário',
            'source'            => 'Fonte',
            'work-date'         => 'Data de trabalho',
            'preset'            => 'Período rápido',
            'preset-hint'       => 'Escolher um período rápido ignora De/Até.',
            'preset-today'      => 'Hoje',
            'preset-this-week'  => 'Esta semana',
            'preset-this-month' => 'Este mês',
            'date-from'         => 'De',
            'date-to'           => 'Até',
            'timezone-tip'      => 'Hora do funcionário (:zone)',
            'session-status'    => 'Status da sessão',
            'status-open'       => 'Aberta (sem saída)',
            'status-closed'     => 'Encerrada',
            'status-long'       => 'Turno longo',
        ],

        'toolbar-actions' => [
            'export-selected' => 'Exportar selecionados',
            'export'          => 'Exportar',
        ],

        'bulk-actions' => [
            'deleted' => ':count registros de presença excluídos.',
            'skipped' => ':count registros ignorados (linhas de dispositivos não podem ser excluídas).',
        ],

        'record-actions' => [
            'add-checkout' => 'Adicionar saída',
        ],
    ],

    'infolist' => [
        'sections' => [
            'record' => [
                'title' => 'Registro de presença',
            ],
            'events' => [
                'title'       => 'Eventos de batida',
                'description' => 'Batidas brutas vinculadas a este registro.',
            ],
        ],

        'entries' => [
            'employee'  => 'Funcionário',
            'work-date' => 'Data de trabalho',
            'check-in'  => 'Entrada',
            'check-out' => 'Saída',
            'worked'    => 'Trabalhado',
            'source'    => 'Fonte',
            'timezone'  => 'Fuso horário do funcionário',
        ],

        'events' => [
            'empty'      => 'Nenhuma batida vinculada a este registro ainda.',
            'punched-at' => 'Hora da batida',
            'direction'  => 'Direção',
            'source'     => 'Fonte',
            'source-ref' => 'Referência da origem',
        ],
    ],
];
