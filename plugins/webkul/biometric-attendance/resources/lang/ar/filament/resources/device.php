<?php

return [
    'title' => 'جهاز البصمة',

    'navigation' => [
        'title' => 'أجهزة البصمة',
        'group' => 'الحضور',
    ],

    'form' => [
        'company'                => 'الشركة',
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
        'punch-direction-mode'   => 'اتجاه البصمة',
        'punch-direction-modes'  => [
            'auto-toggle'      => 'تلقائي (اتجاه غير معروف)',
            'strict-direction' => 'صارم (استنتاج من نوع البصمة)',
            'force-in'         => 'حضور دائماً',
            'force-out'        => 'انصراف دائماً',
        ],
        'punch-direction-mode-helper-text' => 'كيفية وسم البصمات المدفوعة. «تلقائي» يتركها بلا اتجاه ويقرره الاقتران؛ «صارم» يستنتج الاتجاه من نوع السجل؛ «حضور/انصراف دائماً» يثبت كل بصمة.',
        'active'                           => 'نشط',
        'sections'                         => [
            'general'    => ['title' => 'بيانات الجهاز واتجاه البصمة'],
            'connection' => ['title' => 'الشبكة والاتصال'],
            'status'     => [
                'title'      => 'حالة المزامنة',
                'last-sync'  => 'آخر مزامنة',
                'last-error' => 'آخر خطأ',
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'name'                    => 'الاسم',
            'company'                 => 'الشركة',
            'host'                    => 'المضيف / IP',
            'port'                    => 'المنفذ',
            'protocol'                => 'البروتوكول',
            'active'                  => 'نشط',
            'last-sync'               => 'آخر مزامنة',
            'last-error'              => 'آخر خطأ',
            'created-at'              => 'تاريخ الإنشاء',
        ],

        'filters' => [
            'company'           => 'الشركة',
            'active'            => 'نشط',
        ],

        'actions' => [
            'test-connection' => [
                'label' => 'اختبار الاتصال',

                'notification' => [
                    'title'        => 'الجهاز متاح',
                    'failed-title' => 'فشل الاتصال',
                    'missing-host' => 'أدخل عنوان الجهاز (host) أولاً.',
                ],
            ],
            'sync-now' => [
                'label' => 'مزامنة الآن',

                'notification' => [
                    'title'         => 'اكتملت المزامنة',
                    'failed-title'  => 'فشلت المزامنة',
                    'partial-title' => 'اكتملت المزامنة مع تحذيرات',
                    'body'          => 'مخزّن :stored، موجود مسبقًا :skipped، مُستكمل بأثر رجعي :backfilled.',
                    'partial-body'  => 'مخزّن :stored، موجود مسبقًا :skipped، مُستكمل بأثر رجعي :backfilled، فشل دفع :failed.',
                ],
            ],
        ],
    ],
];
