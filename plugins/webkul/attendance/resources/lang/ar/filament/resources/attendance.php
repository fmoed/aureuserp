<?php

return [
    'title' => 'الحضور',

    'navigation' => [
        'title' => 'سجلات الحضور',
    ],

    'form' => [
        'employee'                   => 'الموظف',
        'work-date'                  => 'تاريخ العمل',
        'work-date-hint'             => 'سجل يدوي واحد لكل يوم. الموظف والتاريخ يُقفلان بعد الحفظ — عدّل الأوقات بدلاً من ذلك.',
        'duplicate-day'              => 'لدى :employee سجل يدوي بالفعل في :date. عدّله بدل إنشاء سجل جديد.',
        'check-in'                   => 'الحضور',
        'check-in-not-in-work-date'  => 'يجب أن يكون الحضور ضمن تاريخ العمل.',
        'clock-hint'                 => 'تُدخل الأوقات بتوقيت :zone.',
        'check-out'                  => 'الانصراف',
        'check-out-before-check-in'  => 'يجب أن يكون الانصراف بعد الحضور.',
        'check-out-not-in-work-date' => 'يجب أن يكون الانصراف خلال 16 ساعة من الحضور.',
        'checkout-hint'              => 'يُكمل السجل المفتوح. يُقرأ الوقت بتوقيت الموظف ويجب أن يكون خلال 16 ساعة من الحضور.',
        'long-shift-warning'         => 'تنبيه: هذه الوردية مدتها :hours ساعة.',
        'source'                     => 'المصدر',
        'source-manual'              => 'يدوي',
    ],

    'table' => [
        'columns' => [
            'employee'   => 'الموظف',
            'work-date'  => 'تاريخ العمل',
            'check-in'   => 'الحضور',
            'check-out'  => 'الانصراف',
            'worked'     => 'المدة',
            'source'     => 'المصدر',
            'created-at' => 'تاريخ الإنشاء',
        ],

        'filters' => [
            'employee'         => 'الموظف',
            'source'           => 'المصدر',
            'work-date'        => 'تاريخ العمل',
            'preset'           => 'فترة سريعة',
            'preset-hint'      => 'اختيار فترة سريعة يتجاهل حقلي من/إلى أدناه.',
            'preset-today'     => 'اليوم',
            'preset-this-week' => 'هذا الأسبوع',
            'preset-this-month'=> 'هذا الشهر',
            'date-from'        => 'من',
            'date-to'          => 'إلى',
            'timezone-tip'     => 'بتوقيت الموظف (:zone)',
        ],

        'toolbar-actions' => [
            'export' => 'تصدير',
        ],

        'record-actions' => [
            'add-checkout' => 'إضافة انصراف',
        ],
    ],
];
