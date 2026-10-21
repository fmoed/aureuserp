<?php

return [
    'columns' => [
        'employee'     => 'الموظف',
        'schedule'     => 'جدول الدوام',
        'workdays'     => 'أيام العمل',
        'present'      => 'الحاضر',
        'absent'       => 'الغائب',
        'on-leave'     => 'الإجازات',
        'hours'        => 'ساعات العمل',
        'absence-rate' => 'نسبة الغياب',
    ],

    'notification' => [
        'completed'   => 'تم تصدير :count موظف.',
        'failed'      => 'فشل :count موظف.',
        'cap-title'   => 'حجم التصدير كبير جداً',
        'cap-reached' => 'نتج عن الطلب :count موظف، وهو يتجاوز الحد الأقصى :limit موظف. قلّص نطاق التاريخ أو حدّد موظفاً واحداً.',
    ],
];
