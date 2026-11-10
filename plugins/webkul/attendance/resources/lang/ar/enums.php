<?php

return [
    'shift_type' => [
        'fixed'    => 'وردية ثابتة',
        'flexible' => 'وردية مرنة',
        'split'    => 'وردية مقسمة',
        'open'     => 'وردية مفتوحة',
    ],

    'pairing_mode' => [
        'first_in_last_out' => 'أول دخول وآخر خروج',
        'every_in_out_pair' => 'كل زوج دخول/خروج',
        'strict_intervals'  => 'فترات صارمة',
    ],

    'early_arrival_policy' => [
        'clamp_to_schedule'      => 'تقييد على الجدول',
        'offset_early_departure' => 'تعويض الانصراف المبكر',
        'count_as_overtime'      => 'احتساب كوقت إضافي',
    ],

    'break_policy' => [
        'auto_deduct'               => 'خصم تلقائي للاستراحة',
        'punch_based'               => 'استراحة حسب البصمة',
        'allow_skip_for_early_exit' => 'السماح بتخطي الاستراحة للانصراف المبكر',
    ],

    'missing_checkout_policy' => [
        'incomplete_absent'     => 'ناقص (يُحسب غياباً)',
        'incomplete_half_day'   => 'ناقص (يُحتسب نصف يوم)',
        'auto_credit_scheduled' => 'احتساب الساعات المجدولة (تقديري)',
    ],

    'holiday_policy' => [
        'excuse_all_applicable' => 'إعفاء عطل الشركة والوردية',
        'excuse_shift_only'     => 'إعفاء عطل الوردية فقط',
        'work_required'         => 'العمل مطلوب (تشغيل مستمر)',
    ],

    'mandatory_days_policy' => [
        'treat_as_holiday'           => 'معاملة كعطلة',
        'treat_as_mandatory_workday' => 'معاملة كيوم عمل إلزامي',
        'ignore_in_attendance'       => 'تجاهل في الحضور',
    ],

    'rounding_mode' => [
        'none'       => 'بدون تقريب',
        'nearest_5'  => 'إلى أقرب 5 دقائق',
        'nearest_15' => 'إلى أقرب 15 دقيقة',
    ],
];
