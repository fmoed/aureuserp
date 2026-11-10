<?php

return [
    'columns' => [
        'employee'             => 'الموظف',
        'workdays'             => 'أيام العمل الكلية',
        'present'              => 'أيام الحضور',
        'absent'               => 'أيام الغياب',
        'absence-rate'         => 'نسبة الغياب',
        'on-leave'             => 'أيام الإجازة',
        'holidays'             => 'العطل الرسمية',
        'pending'              => 'أيام الإجازة المعلقة',
        'minutes'              => 'دقائق العمل',
        'scheduled-hours'      => 'الساعات المجدولة',
        'regular-hours'        => 'الساعات النظامية',
        'overtime-hours'       => 'ساعات إضافية',
        'break-hours'          => 'ساعات الاستراحة',
        'late-minutes'         => 'دقائق التأخير',
        'early-leave-minutes'  => 'دقائق الانصراف المبكر',
        'variance-hours'       => 'فارق الساعات',
    ],

    'options' => [
        'department' => 'القسم',
        'calendar'   => 'جدول الدوام',
        'employee'   => 'الموظف',
        'date-from'  => 'من',
        'date-to'    => 'إلى',
    ],

    'notification' => [
        'completed' => 'تم تصدير :count صف.',
        'failed'    => 'فشل :count صف.',
    ],
];
