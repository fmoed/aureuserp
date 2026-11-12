<?php

return [
    'columns' => [
        'employee'  => 'الموظف',
        'work-date' => 'تاريخ العمل',
        'check-in'  => 'الحضور',
        'check-out' => 'الانصراف',
        'scheduled' => 'الساعات المطلوبة',
        'worked'    => 'الساعات الفعلية',
        'remaining' => 'الساعات الناقصة',
        'break'     => 'استراحة',
        'estimated' => 'مقدّر',
        'source'    => 'المصدر',
    ],

    'summary' => [
        'total-for'         => 'الإجمالي — :employee',
        'days-count'        => ':count أيام',
        'missing-checkouts' => ':count بدون انصراف',
    ],

    'notification' => [
        'completed' => 'تم تصدير :count صف.',
        'failed'    => 'فشل :count صف.',
    ],
];
