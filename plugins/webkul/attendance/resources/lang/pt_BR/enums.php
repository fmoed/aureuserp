<?php

return [
    'shift_type' => [
        'fixed'    => 'Turno fixo',
        'flexible' => 'Turno flexível',
        'split'    => 'Turno dividido',
        'open'     => 'Turno aberto',
    ],

    'pairing_mode' => [
        'first_in_last_out' => 'Primeira entrada, última saída',
        'every_in_out_pair' => 'Cada par entrada/saída',
        'strict_intervals'  => 'Intervalos estritos',
    ],

    'early_arrival_policy' => [
        'clamp_to_schedule'      => 'Ajustar ao horário',
        'offset_early_departure' => 'Compensar saída antecipada',
        'count_as_overtime'      => 'Contar como hora extra',
    ],

    'break_policy' => [
        'auto_deduct'               => 'Deduzir intervalo automaticamente',
        'punch_based'               => 'Intervalo por registro',
        'allow_skip_for_early_exit' => 'Permitir pular intervalo para saída antecipada',
    ],

    'missing_checkout_policy' => [
        'incomplete_absent'     => 'Incompleto (contar como ausência)',
        'incomplete_half_day'   => 'Incompleto (creditar meio dia)',
        'auto_credit_scheduled' => 'Creditar horas programadas (estimado)',
    ],

    'holiday_policy' => [
        'excuse_all_applicable' => 'Isentar feriados da empresa e do turno',
        'excuse_shift_only'     => 'Isentar apenas feriados do turno',
        'work_required'         => 'Trabalho exigido (operação contínua)',
    ],

    'mandatory_days_policy' => [
        'treat_as_holiday'           => 'Tratar como feriado',
        'treat_as_mandatory_workday' => 'Tratar como dia útil obrigatório',
        'ignore_in_attendance'       => 'Ignorar na presença',
    ],

    'rounding_mode' => [
        'none'       => 'Sem arredondamento',
        'nearest_5'  => 'Arredondar para 5 minutos',
        'nearest_15' => 'Arredondar para 15 minutos',
    ],
];
