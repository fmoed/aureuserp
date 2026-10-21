<?php

return [
    'title' => 'الحاضر والغائب',

    'navigation' => [
        'title' => 'الحاضر والغائب',
    ],

    'form' => [
        'employee'       => 'الموظف',
        'all-employees'  => 'كل الموظفين',
        'date-from'      => 'من',
        'date-to'        => 'إلى',
        'range-too-wide' => 'يجب ألا يتجاوز النطاق :days يوماً.',
    ],

    'tabs' => [
        'daily'   => 'يومي',
        'monthly' => 'شهري',
    ],

    'table' => [
        'columns' => [
            'date'      => 'التاريخ',
            'employee'  => 'الموظف',
            'status'    => 'الحالة',
            'check-in'  => 'الحضور',
            'check-out' => 'الانصراف',
            'minutes'   => 'المدة',
        ],

        'empty' => 'لا توجد أيام عمل في هذا النطاق.',
    ],

    'statuses' => [
        'present'  => 'حاضر',
        'absent'   => 'غائب',
        'pending'  => 'معلق',
        'half-day' => 'نصف يوم',
    ],

    'grid' => [
        'legend' => 'A غائب · L إجازة · P معلق · H عطلة · فارغ حاضر.',
    ],

    'export' => 'تقرير الغياب',
];
