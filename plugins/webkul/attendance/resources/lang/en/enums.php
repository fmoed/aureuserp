<?php

return [
    'shift_type' => [
        'fixed'    => 'Fixed shift',
        'flexible' => 'Flexible shift',
        'split'    => 'Split shift',
        'open'     => 'Open shift',
    ],

    'pairing_mode' => [
        'first_in_last_out' => 'First in, last out',
        'every_in_out_pair' => 'Every in/out pair',
        'strict_intervals'  => 'Strict intervals',
    ],

    'early_arrival_policy' => [
        'clamp_to_schedule'      => 'Clamp to schedule',
        'offset_early_departure' => 'Offset early departure',
        'count_as_overtime'      => 'Count as overtime',
    ],

    'break_policy' => [
        'auto_deduct'               => 'Auto deduct break',
        'punch_based'               => 'Punch-based break',
        'allow_skip_for_early_exit' => 'Allow skipping break for early exit',
    ],

    'missing_checkout_policy' => [
        'incomplete_absent'     => 'Incomplete (count as absence)',
        'incomplete_half_day'   => 'Incomplete (credit half day)',
        'auto_credit_scheduled' => 'Credit scheduled hours (estimated)',
    ],

    'holiday_policy' => [
        'excuse_all_applicable' => 'Excuse company and shift holidays',
        'excuse_shift_only'     => 'Excuse shift holidays only',
        'work_required'         => 'Work required (continuous operation)',
    ],

    'mandatory_days_policy' => [
        'treat_as_holiday'           => 'Treat as holiday',
        'treat_as_mandatory_workday' => 'Treat as mandatory workday',
        'ignore_in_attendance'       => 'Ignore in attendance',
    ],

    'rounding_mode' => [
        'none'       => 'No rounding',
        'nearest_5'  => 'Nearest 5 minutes',
        'nearest_15' => 'Nearest 15 minutes',
    ],
];
