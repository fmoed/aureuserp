<?php

return [
    'title' => 'Registro de presença',

    'navigation' => [
        'title' => 'Registro de presença',
    ],

    'form' => [
        'department'      => 'Departamento',
        'all-departments' => 'Todos os departamentos',
        'calendar'        => 'Jornada de trabalho',
        'all-calendars'   => 'Todas as jornadas',
        'heading'         => 'Filtros do registro',
        'employee'        => 'Funcionário',
        'all-employees'   => 'Todos os funcionários',
        'view-mode'       => 'Intervalo de tempo',
        'mode-month'      => 'Mês',
        'mode-range'      => 'Período personalizado',
        'mode-year'       => 'Ano',
        'month'           => 'Mês',
        'year'            => 'Ano',
        'year-hint'       => 'Dados dos últimos dois anos.',
        'date-from'       => 'De',
        'date-to'         => 'Até',
        'range-hint'      => 'Máximo de :days dias.',
        'apply'           => 'Aplicar',
        'reset'           => 'Limpar',
        'range-too-wide'  => 'O intervalo não deve exceder :days dias.',
        'month-future'    => 'O mês selecionado está no futuro.',
    ],

    'filter-chips' => [
        'department' => 'Departamento',
        'calendar'   => 'Jornada de trabalho',
        'period'     => 'Período',
        'employee'   => 'Funcionário',
        'clear'      => 'Limpar',
    ],

    'table' => [
        'columns' => [
            'employee'          => 'Funcionário',
            'schedule'          => 'Escala de trabalho',
            'rate'              => 'Taxa de ausências',
            'workdays'          => 'Dias úteis',
            'scheduled-hours'   => 'Horas programadas',
            'worked-hours'      => 'Horas trabalhadas',
            'overtime-hours'    => 'Horas extras',
            'shortfall-hours'   => 'Horas faltantes',
            'late-minutes'      => 'Atraso (min)',
            'late-occurrences'  => ':count atrasos',
            'variance-hours'    => 'Variação de horas',
            'missing-checkouts' => 'Saídas ausentes',
            'break'             => 'Intervalo',
            'late'              => 'Atraso',
            'early-leave'       => 'Saída antecipada',
            'estimated'         => 'Estimado',
        ],

        'empty' => 'Sem dias úteis neste intervalo. Funcionários sem escala ficam ocultos.',

        'view' => [
            'totals' => 'Resumo por funcionário',
            'days'   => 'Registro diário',
        ],
    ],

    'statuses' => [
        'short' => [
            'present'          => 'P',
            'absent'           => 'A',
            'on-leave'         => 'L',
            'half-day'         => '½',
            'pending'          => '?',
            'holiday'          => 'H',
            'weekly-off'       => 'W',
            'missing-checkout' => '!',
            'weekend-present'  => 'W+',
            'holiday-present'  => 'H+',
            'future'           => '',
        ],
        'present'          => 'Presente',
        'absent'           => 'Ausente',
        'on-leave'         => 'De licença',
        'pending'          => 'Pendente',
        'holiday'          => 'Feriado',
        'half-day'         => 'Meio dia',
        'weekly-off'       => 'Folga',
        'missing-checkout' => 'Saída ausente',
        'weekend-present'  => 'Trabalho na folga',
        'holiday-present'  => 'Trabalho no feriado',
        'future'           => 'Próximo',
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

    'inspect' => [
        'action_label'        => 'Detalhes do dia',
        'heading'             => 'Detalhes diários de presença',
        'close'               => 'Fechar',
        'evaluation_title'    => 'Avaliação do dia',
        'status'              => 'Status',
        'shift'               => 'Jornada programada',
        'no_shift'            => 'Nenhuma jornada atribuída',
        'worked_hours'        => 'Horas trabalhadas / programadas',
        'first_in_last_out'   => 'Primeira entrada → Última saída',
        'late_minutes'        => 'Atraso',
        'early_leave_minutes' => 'Saída antecipada',
        'overtime_minutes'    => 'Horas extras',
        'break_minutes'       => 'Intervalo',
        'missing_checkout'    => 'Saída ausente (!)',
        'raw_punches_title'   => 'Linha do tempo de marcações brutas',
        'no_punches'          => 'Nenhuma marcação bruta registrada para este dia.',
        'duplicate_scan'      => 'Marcação duplicada (ignorada)',
        'recorded'            => 'Registrada',
        'paired'              => 'Pareada',
        'unpaired'            => 'Não pareada',
        'actions'             => [
            'complete_checkout'  => 'Completar saída ausente',
            'checkout_completed' => 'Saída registrada com sucesso.',
            'add_manual'         => 'Adicionar presença manual',
            'manual_added'       => 'Presença manual registrada com sucesso.',
        ],
    ],
];
