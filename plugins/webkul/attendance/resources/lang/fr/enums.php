<?php

return [
    'shift_type' => [
        'fixed'    => 'Poste fixe',
        'flexible' => 'Poste flexible',
        'split'    => 'Poste fractionné',
        'open'     => 'Poste ouvert',
    ],

    'pairing_mode' => [
        'first_in_last_out' => 'Première entrée, dernière sortie',
        'every_in_out_pair' => 'Chaque paire entrée/sortie',
        'strict_intervals'  => 'Intervalles stricts',
    ],

    'early_arrival_policy' => [
        'clamp_to_schedule'      => 'Aligner sur le planning',
        'offset_early_departure' => 'Compenser le départ anticipé',
        'count_as_overtime'      => 'Compter comme heures supplémentaires',
    ],

    'break_policy' => [
        'auto_deduct'               => 'Déduire automatiquement la pause',
        'punch_based'               => 'Pause basée sur les pointages',
        'allow_skip_for_early_exit' => "Autoriser l'omission de la pause pour un départ anticipé",
    ],

    'missing_checkout_policy' => [
        'incomplete_absent'     => 'Incomplet (compter comme absence)',
        'incomplete_half_day'   => 'Incomplet (créditer une demi-journée)',
        'auto_credit_scheduled' => 'Créditer les heures planifiées (estimé)',
    ],

    'holiday_policy' => [
        'excuse_all_applicable' => 'Exonérer les jours fériés société et poste',
        'excuse_shift_only'     => 'Exonérer uniquement les jours fériés du poste',
        'work_required'         => 'Travail requis (opération continue)',
    ],

    'mandatory_days_policy' => [
        'treat_as_holiday'           => 'Traiter comme jour férié',
        'treat_as_mandatory_workday' => 'Traiter comme jour travaillé obligatoire',
        'ignore_in_attendance'       => 'Ignorer dans la présence',
    ],

    'rounding_mode' => [
        'none'       => 'Aucun arrondi',
        'nearest_5'  => 'Arrondi aux 5 minutes',
        'nearest_15' => 'Arrondi aux 15 minutes',
    ],
];
