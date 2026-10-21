<?php

return [
    'columns' => [
        'employee'     => 'الموظف',
        'workdays'     => 'أيام العمل الكلية',
        'present'      => 'أيام الحضور',
        'absent'       => 'أيام الغياب',
        'absence-rate' => 'نسبة الغياب',
        'on-leave'     => 'أيام الإجازة',
        'holidays'     => 'العطل الرسمية',
        'pending'      => 'أيام الإجازة المعلقة',
        'minutes'      => 'دقائق العمل',
    ],

    'options' => [
        'employee'  => 'الموظف',
        'date-from' => 'من',
        'date-to'   => 'إلى',
    ],

    'notification' => [
        'completed' => 'تم تصدير :count صف.',
        'failed'    => 'فشل :count صف.',
    ],
];
