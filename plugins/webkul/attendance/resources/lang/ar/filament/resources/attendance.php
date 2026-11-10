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
        'check-out-not-in-work-date' => 'يجب أن يكون الانصراف خلال :hours ساعة من الحضور.',
        'checkout-hint'              => 'يُكمل السجل المفتوح. يُقرأ الوقت بتوقيت الموظف ويجب أن يكون خلال :hours ساعة من الحضور.',
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
            'next-day'   => 'ليلي',
            'estimated'  => 'مقدّر',
            'worked'     => 'المدة',
            'source'     => 'المصدر',
            'created-at' => 'تاريخ الإنشاء',
        ],

        'filters' => [
            'department'       => 'القسم',
            'calendar'         => 'جدول الدوام',
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
            'session-status'   => 'حالة الجلسة',
            'status-open'      => 'مفتوحة (بدون انصراف)',
            'status-closed'    => 'مكتملة',
            'status-long'      => 'دوام طويل',
        ],

        'toolbar-actions' => [
            'export-selected' => 'تصدير المحدد',
            'export'          => 'تصدير',
        ],

        'bulk-actions' => [
            'deleted' => 'تم حذف :count سجلاً من سجلات الحضور.',
            'skipped' => 'تم تجاهل :count سجلاً (لا يمكن حذف السجلات الواردة من الأجهزة).',
        ],

        'record-actions' => [
            'add-checkout' => 'إضافة انصراف',
        ],
    ],

    'infolist' => [
        'sections' => [
            'record' => [
                'title' => 'سجل الحضور',
            ],
            'events' => [
                'title'       => 'أحداث البصمات',
                'description' => 'البصمات الخام المرتبطة بهذا السجل.',
            ],
        ],

        'entries' => [
            'employee'  => 'الموظف',
            'work-date' => 'تاريخ العمل',
            'check-in'  => 'الحضور',
            'check-out' => 'الانصراف',
            'worked'    => 'المدة',
            'source'    => 'المصدر',
            'timezone'  => 'المنطقة الزمنية للموظف',
        ],

        'events' => [
            'empty'      => 'لا توجد بصمات مرتبطة بهذا السجل بعد.',
            'punched-at' => 'وقت البصمة',
            'direction'  => 'الاتجاه',
            'source'     => 'المصدر',
            'source-ref' => 'مرجع المصدر',
        ],
    ],
];
