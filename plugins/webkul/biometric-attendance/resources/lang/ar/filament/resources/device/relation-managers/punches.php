<?php

return [
    'title' => 'البصمات',

    'table' => [
        'columns' => [
            'user-id'    => 'معرف مستخدم الجهاز',
            'employee'   => 'الموظف',
            'unmatched'  => 'غير مطابق',
            'punch-time' => 'وقت البصمة',
            'raw-type'   => 'النوع الخام',
            'pushed-at'  => 'وقت الإرسال',
            'pending'    => 'معلق',
        ],

        'filters' => [
            'match-status' => 'المطابقة',
            'unmatched'    => 'بصمات غير مطابقة',
            'matched'      => 'بصمات مطابقة',
            'push-status'  => 'حالة الإرسال',
            'pushed'       => 'تم الإرسال',
        ],

        'empty' => 'لا توجد بصمات مسجلة لهذا الجهاز بعد.',
    ],

    'actions' => [
        'rematch' => [
            'label'     => 'إعادة مطابقة البصمات غير المطابقة',
            'completed' => 'تمت مطابقة :count بصمة مع الموظفين.',
        ],

        'assign' => [
            'label'     => 'تعيين إلى موظف',
            'employee'  => 'الموظف',
            'assigned'  => 'تم حفظ رقم البصمة لـ :name واستعادة :count بصمة سابقة.',
            'conflict'  => 'الباركود :barcode مسند بالفعل للموظف :name في هذه الشركة.',
        ],
    ],
];
