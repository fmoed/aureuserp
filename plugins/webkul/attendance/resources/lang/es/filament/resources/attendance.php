<?php

return [
    'title' => 'Asistencias',

    'navigation' => [
        'title' => 'Registros de asistencia',
    ],

    'form' => [
        'employee'                   => 'Empleado',
        'work-date'                  => 'Fecha de trabajo',
        'work-date-hint'             => 'Un registro manual por día. Empleado y fecha se bloquean al guardar.',
        'duplicate-day'              => ':employee ya tiene un registro manual el :date. Edítelo en su lugar.',
        'check-in'                   => 'Entrada',
        'check-in-not-in-work-date'  => 'La entrada debe estar dentro de la fecha de trabajo.',
        'clock-hint'                 => 'Horas en :zone.',
        'check-out'                  => 'Salida',
        'check-out-before-check-in'  => 'La salida debe ser posterior a la entrada.',
        'check-out-not-in-work-date' => 'La salida debe estar dentro de las 16 horas posteriores a la entrada.',
        'checkout-hint'              => 'Completa la fila abierta, en la zona del empleado y dentro de las 16 horas.',
        'long-shift-warning'         => 'Aviso: este turno dura :hours horas.',
        'source'                     => 'Fuente',
        'source-manual'              => 'Manual',
    ],

    'table' => [
        'columns' => [
            'employee'   => 'Empleado',
            'work-date'  => 'Fecha de trabajo',
            'check-in'   => 'Entrada',
            'check-out'  => 'Salida',
            'worked'     => 'Trabajado',
            'source'     => 'Fuente',
            'created-at' => 'Creado el',
        ],

        'filters' => [
            'employee'          => 'Empleado',
            'source'            => 'Fuente',
            'work-date'         => 'Fecha de trabajo',
            'preset'            => 'Período rápido',
            'preset-hint'       => 'Elegir un período rápido ignora Desde/Hasta.',
            'preset-today'      => 'Hoy',
            'preset-this-week'  => 'Esta semana',
            'preset-this-month' => 'Este mes',
            'date-from'         => 'Desde',
            'date-to'           => 'Hasta',
            'timezone-tip'      => 'Hora del empleado (:zone)',
        ],

        'toolbar-actions' => [
            'export' => 'Exportar',
        ],

        'record-actions' => [
            'add-checkout' => 'Añadir salida',
        ],
    ],
];
