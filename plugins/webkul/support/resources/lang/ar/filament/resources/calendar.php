<?php

return [
    'title' => 'جداول العمل',

    'navigation' => [
        'title' => 'جداول العمل',
    ],

    'groups' => [
        'status'     => 'الحالة',
        'created-by' => 'أنشئ بواسطة',
        'created-at' => 'تاريخ الإنشاء',
        'updated-at' => 'تاريخ التحديث',
    ],

    'form' => [
        'sections' => [
            'general' => [
                'title'  => 'معلومات عامة',
                'fields' => [
                    'name'                  => 'الاسم',
                    'schedule-name'         => 'اسم الجدول',
                    'schedule-name-tooltip' => 'يرجى كتابة اسم وصفي لجدول العمل.',
                    'timezone'              => 'المنطقة الزمنية',
                    'timezone-tooltip'      => 'يرجى تحديد المنطقة الزمنية لجدول العمل.',
                    'company'               => 'الشركة',
                    'shift-type'            => 'نوع الوردية',
                ],
            ],

            'windows' => [
                'title'          => 'نوافذ قبول البصمة وطريقة الاقتران',
                'description'    => 'تحدد متى يبدأ جهاز البصمة بقبول بصمة الدخول لهذه الوردية ومتى ينتهي قبول بصمة الانصراف (بما في ذلك الورديات الليلية التي تعبر منتصف الليل).',
                'minutes-suffix' => 'دقيقة',
                'overlap-error'  => 'يجب أن يبقى طول الوردية مع نافذتي القبول أقل من 24 ساعة.',
                'preview-in'     => 'تبدأ الوردية :start — يُفتح قبول بصمة الدخول من الساعة :open',
                'preview-out'    => 'تنتهي الوردية :end — يمتد قبول بصمة الخروج حتى الساعة :close',
                'preview-split'  => 'الفترة :index (:start–:end): قبول البصمة من :open إلى :close',
                'fields'         => [
                    'pairing-mode'    => 'طريقة اقتران البصمات',
                    'check-in-before' => 'هامش قبول الدخول (قبل بداية الوردية)',
                    'check-out-after' => 'هامش قبول الخروج (بعد نهاية الوردية)',
                ],
            ],

            'punctuality' => [
                'title'       => 'الالتزام بالوقت والحضور المبكر',
                'description' => 'فترات السماح للتأخير والانصراف المبكر، وكيفية احتساب الحضور قبل بداية الوردية.',
                'fields'      => [
                    'late-grace'           => 'سماح التأخير',
                    'early-leave-grace'    => 'سماح الانصراف المبكر',
                    'early-arrival-policy' => 'سياسة الحضور المبكر',
                    'max-early-offset'     => 'أقصى تعويض للحضور المبكر',
                ],
            ],

            'breaks' => [
                'title'       => 'الاستراحات',
                'description' => 'طريقة احتساب أو خصم وقت الاستراحة خلال الوردية.',
            ],

            'policies' => [
                'title'       => 'قواعد وسياسات الوردية (نوافذ البصمة، التأخير، والإضافي)',
                'description' => 'تحديد نوافذ قبول بصمة الدخول والخروج، فترات سماح التأخير والانصراف المبكر، سياسة نسيان الانصراف، وقواعد العمل الإضافي لهذه الوردية.',
                'fields'      => [
                    'break-policy'             => 'سياسة الاستراحة',
                    'break-duration'           => 'مدة الاستراحة',
                    'break-trigger'            => 'حد تفعيل الاستراحة',
                    'holiday-policy'           => 'سياسة العطل',
                    'missing-checkout-policy'  => 'سياسة بصمة الخروج الناقصة',
                    'missing-checkout-inherit' => 'وراثة من إعدادات الحضور',
                    'half-day-threshold'       => 'حد نصف اليوم',
                    'absent-threshold'         => 'حد الغياب',
                    'overtime-enabled'         => 'تمكين العمل الإضافي',
                    'min-overtime'             => 'الحد الأدنى للإضافي',
                ],
            ],

            'configuration' => [
                'title'                   => 'تكوين ساعات وأوقات الوردية',
                'split-min-periods-error' => 'الوردية المقسمة تتطلب فترتَي عمل على الأقل (الفترة الأولى والفترة الثانية).',
                'hours-mismatch-error'    => 'مجموع ساعات الفترات المدخلة (:actual ساعة) لا يطابق "ساعات في اليوم" (:expected ساعة). يرجى مطابقة الساعات قبل الحفظ.',
                'custom-schedule-hint'    => 'ملاحظة: يحتوي هذا الشفت على ساعات مخصصة لبعض الأيام في جدول فترات العمل بالأسفل. لن يتم استبدال تخصيصاتك اليومية إلا إذا قمت بتغيير أيام العمل أو الفترات هنا.',
                'fields'                  => [
                    'hours-per-day'                   => 'ساعات في اليوم',
                    'hours-per-day-suffix'            => 'ساعات',
                    'full-time-required-hours'        => 'ساعات الدوام الكامل المطلوبة',
                    'full-time-required-hours-suffix' => 'ساعات في الأسبوع',
                    'work-days'                       => 'أيام العمل الأسبوعية',
                    'shift-periods'                   => 'فترات الدوام اليومية (بداية ونهاية كل فترة)',
                    'period-from'                     => 'من الساعة (بداية الفترة)',
                    'period-to'                       => 'إلى الساعة (نهاية الفترة)',
                    'day-period'                      => 'فترة اليوم',
                    'add-period'                      => 'إضافة فترة عمل أخرى (جزء جديد)',
                ],
            ],

            'flexibility' => [
                'title'  => 'المرونة',
                'fields' => [
                    'status'                     => 'الحالة',
                    'two-weeks-calendar'         => 'جدول أسبوعين',
                    'two-weeks-calendar-tooltip' => 'تمكين جدول عمل متناوب لأسبوعين.',
                    'flexible-hours'             => 'ساعات مرنة',
                    'flexible-hours-tooltip'     => 'السماح للموظفين بساعات عمل مرنة.',
                ],
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'id'             => 'المعرف',
            'name'           => 'اسم الجدول',
            'timezone'       => 'المنطقة الزمنية',
            'company'        => 'الشركة',
            'flexible-hours' => 'ساعات مرنة',
            'status'         => 'الحالة',
            'daily-hours'    => 'الساعات اليومية',
            'created-by'     => 'أنشئ بواسطة',
            'created-at'     => 'تاريخ الإنشاء',
            'updated-at'     => 'تاريخ التحديث',
        ],

        'filters' => [
            'company'                  => 'الشركة',
            'is-active'                => 'الحالة',
            'two-week-calendar'        => 'جدول أسبوعين',
            'flexible-hours'           => 'ساعات مرنة',
            'timezone'                 => 'المنطقة الزمنية',
            'name'                     => 'اسم الجدول',
            'attendance'               => 'الحضور',
            'created-by'               => 'أنشئ بواسطة',
            'daily-hours'              => 'الساعات اليومية',
            'full-time-required-hours' => 'ساعات الدوام الكامل المطلوبة',
            'updated-at'               => 'تاريخ التحديث',
            'created-at'               => 'تاريخ الإنشاء',
        ],

        'groups' => [
            'name'           => 'اسم الجدول',
            'status'         => 'الحالة',
            'timezone'       => 'المنطقة الزمنية',
            'flexible-hours' => 'ساعات مرنة',
            'daily-hours'    => 'الساعات اليومية',
            'created-by'     => 'أنشئ بواسطة',
            'created-at'     => 'تاريخ الإنشاء',
            'updated-at'     => 'تاريخ التحديث',
        ],

        'actions' => [
            'restore' => [
                'notification' => [
                    'title' => 'تم استعادة جدول العمل',
                    'body'  => 'تم استعادة جدول العمل بنجاح.',
                ],
            ],

            'delete' => [
                'notification' => [
                    'title' => 'تم حذف جدول العمل',
                    'body'  => 'تم حذف جدول العمل بنجاح.',
                ],
            ],

            'force-delete' => [
                'notification' => [
                    'title' => 'تم حذف جدول العمل نهائياً',
                    'body'  => 'تم حذف جدول العمل نهائياً بنجاح.',
                ],
            ],
        ],

        'bulk-actions' => [
            'restore' => [
                'notification' => [
                    'title' => 'تم استعادة جداول العمل',
                    'body'  => 'تم استعادة جداول العمل بنجاح.',
                ],
            ],

            'delete' => [
                'notification' => [
                    'title' => 'تم حذف جداول العمل',
                    'body'  => 'تم حذف جداول العمل بنجاح.',
                ],
            ],

            'force-delete' => [
                'notification' => [
                    'title' => 'تم حذف جداول العمل نهائياً',
                    'body'  => 'تم حذف جداول العمل نهائياً بنجاح.',
                ],
            ],
        ],
    ],

    'infolist' => [
        'sections' => [
            'general' => [
                'title'   => 'معلومات عامة',
                'entries' => [
                    'name'                  => 'الاسم',
                    'schedule-name'         => 'اسم الجدول',
                    'schedule-name-tooltip' => 'يرجى كتابة اسم وصفي لجدول العمل.',
                    'timezone'              => 'المنطقة الزمنية',
                    'timezone-tooltip'      => 'يرجى تحديد المنطقة الزمنية لجدول العمل.',
                    'company'               => 'الشركة',
                ],
            ],

            'configuration' => [
                'title'   => 'تكوين ساعات العمل',
                'entries' => [
                    'hours-per-day'                   => 'ساعات في اليوم',
                    'hours-per-day-suffix'            => 'ساعات',
                    'full-time-required-hours'        => 'ساعات الدوام الكامل المطلوبة',
                    'full-time-required-hours-suffix' => 'ساعات في الأسبوع',
                ],
            ],

            'flexibility' => [
                'title'   => 'المرونة',
                'entries' => [
                    'status'                     => 'الحالة',
                    'two-weeks-calendar'         => 'جدول أسبوعين',
                    'two-weeks-calendar-tooltip' => 'تمكين جدول عمل متناوب لأسبوعين.',
                    'flexible-hours'             => 'ساعات مرنة',
                    'flexible-hours-tooltip'     => 'السماح للموظفين بساعات عمل مرنة.',
                ],
            ],
        ],
    ],
];
