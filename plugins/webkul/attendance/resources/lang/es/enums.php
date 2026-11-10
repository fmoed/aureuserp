<?php

return [
    'shift_type' => [
        'fixed'    => 'Turno fijo',
        'flexible' => 'Turno flexible',
        'split'    => 'Turno dividido',
        'open'     => 'Turno abierto',
    ],

    'pairing_mode' => [
        'first_in_last_out' => 'Primera entrada, última salida',
        'every_in_out_pair' => 'Cada par entrada/salida',
        'strict_intervals'  => 'Intervalos estrictos',
    ],

    'early_arrival_policy' => [
        'clamp_to_schedule'      => 'Ajustar al horario',
        'offset_early_departure' => 'Compensar salida anticipada',
        'count_as_overtime'      => 'Contar como horas extra',
    ],

    'break_policy' => [
        'auto_deduct'               => 'Deducir descanso automáticamente',
        'punch_based'               => 'Descanso según fichajes',
        'allow_skip_for_early_exit' => 'Permitir omitir descanso por salida anticipada',
    ],

    'missing_checkout_policy' => [
        'incomplete_absent'     => 'Incompleto (contar como ausencia)',
        'incomplete_half_day'   => 'Incompleto (acreditar medio día)',
        'auto_credit_scheduled' => 'Acreditar horas programadas (estimado)',
    ],

    'holiday_policy' => [
        'excuse_all_applicable' => 'Eximir festivos de empresa y turno',
        'excuse_shift_only'     => 'Eximir solo festivos del turno',
        'work_required'         => 'Trabajo requerido (operación continua)',
    ],

    'mandatory_days_policy' => [
        'treat_as_holiday'           => 'Tratar como festivo',
        'treat_as_mandatory_workday' => 'Tratar como día laborable obligatorio',
        'ignore_in_attendance'       => 'Ignorar en asistencia',
    ],

    'rounding_mode' => [
        'none'       => 'Sin redondeo',
        'nearest_5'  => 'Redondear a 5 minutos',
        'nearest_15' => 'Redondear a 15 minutos',
    ],
];
