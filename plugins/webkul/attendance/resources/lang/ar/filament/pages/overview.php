<?php

return [
    'title' => 'سجل التحضير',

    'navigation' => [
        'title' => 'سجل التحضير',
    ],

    'form' => [
        'heading'        => 'تصفية السجل',
        'employee'       => 'الموظف',
        'all-employees'  => 'كل الموظفين',
        'view-mode'      => 'النطاق الزمني',
        'mode-month'     => 'شهر',
        'mode-range'     => 'فترة مخصصة',
        'mode-year'      => 'سنة',
        'month'          => 'الشهر',
        'year'           => 'السنة',
        'year-hint'      => 'البيانات المتاحة لآخر سنتين.',
        'date-from'      => 'من',
        'date-to'        => 'إلى',
        'range-hint'     => 'الحد الأقصى :days يوماً.',
        'apply'          => 'تطبيق',
        'reset'          => 'إعادة تعيين',
        'range-too-wide' => 'يجب ألا يتجاوز النطاق :days يوماً.',
        'month-future'   => 'الشهر المحدد في المستقبل.',
    ],

    'filter-chips' => [
        'period'   => 'الفترة',
        'employee' => 'الموظف',
        'clear'    => 'مسح',
    ],

    'table' => [
        'columns' => [
            'employee' => 'الموظف',
            'schedule' => 'جدول الدوام',
            'rate'     => 'نسبة الغياب',
            'workdays' => 'أيام العمل',
        ],

        'empty' => 'لا توجد أيام عمل في هذا النطاق. الموظفون بدون جدول دوام لا يظهرون.',

        'view' => [
            'totals' => 'ملخص الموظفين',
            'days'   => 'السجل اليومي',
        ],
    ],

    'statuses' => [
        'short' => [
            'present'    => 'ح',
            'absent'     => 'غ',
            'on-leave'   => 'ج',
            'half-day'   => 'ن',
            'pending'    => 'م',
            'holiday'    => 'ع',
            'weekly-off' => 'ر',
            'future'     => '',
        ],
        'present'    => 'حاضر',
        'absent'     => 'غائب',
        'on-leave'   => 'إجازة',
        'pending'    => 'معلق',
        'holiday'    => 'عطلة',
        'half-day'   => 'نصف يوم',
        'weekly-off' => 'إجازة أسبوعية',
        'future'     => 'قادم',
    ],

    'empty' => [
        'heading' => 'لا توجد أيام عمل للعرض.',
    ],

    'grid' => [
        'summary'      => 'الملخص',
        'pending-note' => 'الإجازة المعلقة تُحتسب ضمن الغياب حتى اعتمادها.',
        'chart'        => 'الحاضر والغائب يومياً',
        'legend'       => 'دليل الحالات والألوان',
        'totals'       => [
            'employees' => 'الموظفون',
            'present'   => 'أيام الحضور',
            'absent'    => 'أيام الغياب',
            'on-leave'  => 'أيام الإجازة',
            'pending'   => 'معلق',
            'holidays'  => 'العطلات',
            'rate'      => 'نسبة الغياب',
        ],
    ],

    'export' => 'تقرير الغياب الإجمالي (سطر لكل موظف)',

    'export-detail' => 'كشف الحضور (المصفوفة الشبكية)',
];
