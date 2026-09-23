<?php

return [
    'title' => 'جهاز البصمة',

    'navigation' => [
        'title' => 'أجهزة البصمة',
        'group' => 'الحضور',
    ],

    'form' => [
        'name'                   => 'الاسم',
        'host'                   => 'المضيف / IP',
        'host-helper-text'       => 'عنوان IP الخاص بالجهاز والذي يمكن الوصول إليه من هذا الخادم عبر المنفذ 4370.',
        'port'                   => 'المنفذ',
        'protocol'               => 'البروتوكول',
        'protocol-helper-text'   => 'استخدم TCP أولًا. بعض الأجهزة القديمة لا تستجيب إلا عبر UDP.',
        'comm-key'               => 'مفتاح COMM',
        'comm-key-helper-text'   => 'كلمة مرور الاتصال من شاشة الجهاز: القائمة ← COMM ← اتصال الحاسب. ليست كلمة مرور الدخول. اترك 0 عند عدم وجودها (الافتراضي).',
        'timezone'               => 'المنطقة الزمنية للجهاز',
        'timezone-helper-text'   => 'المنطقة الزمنية لساعة الجهاز. ابدأ الكتابة للبحث، مثل Asia/Riyadh.',
        'active'                 => 'نشط',
    ],

    'table' => [
        'columns' => [
            'name'       => 'الاسم',
            'host'       => 'المضيف / IP',
            'port'       => 'المنفذ',
            'protocol'   => 'البروتوكول',
            'active'     => 'نشط',
            'last-sync'  => 'آخر مزامنة',
            'created-at' => 'تاريخ الإنشاء',
        ],

        'actions' => [
            'test-connection' => [
                'label' => 'اختبار الاتصال',

                'notification' => [
                    'title'        => 'الجهاز متاح',
                    'failed-title' => 'فشل الاتصال',
                ],
            ],
        ],
    ],
];
