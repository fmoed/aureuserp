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
            'linked-row'  => 'الصف المرتبط',
        ],

        'directions' => [
            'unknown' => 'غير معروف',
            'in'      => 'دخول',
            'out'     => 'خروج',
        ],

        'filters' => [
            'employee'   => 'الموظف',
            'source'     => 'المصدر',
            'punched-at' => 'تاريخ البصمة',
            'date-from'  => 'من',
            'date-to'    => 'إلى',
        ],

        'toolbar-actions' => [
            'export' => 'تصدير',
        ],
    ],
];
