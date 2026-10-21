<?php

return [
    'title' => 'حدث حضور',

    'navigation' => [
        'title' => 'أحداث الحضور',
    ],

    'table' => [
        'columns' => [
            'employee'    => 'الموظف',
            'punched-at'  => 'وقت البصمة',
            'direction'   => 'الاتجاه',
            'source'      => 'المصدر',
            'linked-row'  => 'السجل المرتبط',
            'linked-hint' => 'الخانة الفارغة تعني بانتظار الربط التلقائي. صحّح الأوقات من الحضور وليس هنا.',
        ],

        'directions' => [
            'unknown' => 'غير معروف',
            'in'      => 'دخول',
            'out'     => 'خروج',
        ],

        'filters' => [
            'employee'       => 'الموظف',
            'source'         => 'المصدر',
            'punched-at'     => 'تاريخ البصمة',
            'date-from'      => 'من',
            'date-to'        => 'إلى',
            'range-too-wide' => 'يجب ألا يتجاوز النطاق :days يوماً.',
        ],

        'toolbar-actions' => [
            'export' => 'تصدير',
        ],
    ],
];
