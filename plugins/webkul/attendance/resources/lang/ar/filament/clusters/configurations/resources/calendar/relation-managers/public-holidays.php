<?php

return [
    'title' => 'العطل الرسمية',

    'scopes' => [
        'company' => 'على مستوى الشركة',
        'shift'   => 'خاصة بالوردية',
    ],

    'table' => [
        'columns' => [
            'name'      => 'العطلة',
            'scope'     => 'النطاق',
            'date-from' => 'من',
            'date-to'   => 'إلى',
        ],
    ],

    'actions' => [
        'create' => [
            'label' => 'إضافة عطلة للوردية',
        ],

        'assign-existing' => [
            'label'     => 'تطبيق عطلة موجودة',
            'holiday'   => 'عطلة الشركة',
            'assigned'  => 'تم تطبيق العطلة على هذه الوردية.',
        ],
    ],
];
