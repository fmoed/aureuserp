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
        'check-out-not-in-work-date' => 'La salida debe estar dentro de las :hours horas posteriores a la entrada.',
        'checkout-hint'              => 'Completa la fila abierta, en la zona del empleado y dentro de las :hours horas.',
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
            'next-day'   => 'Nocturno',
            'estimated'  => 'Estimado',
            'worked'     => 'Trabajado',
            'source'     => 'Fuente',
            'created-at' => 'Creado el',
        ],

        'filters' => [
            'department'        => 'Departamento',
            'calendar'          => 'Horario de trabajo',
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
            'session-status'    => 'Estado de la sesión',
            'status-open'       => 'Abierta (sin salida)',
            'status-closed'     => 'Cerrada',
            'status-long'       => 'Turno largo',
        ],

        'toolbar-actions' => [
            'export-selected' => 'Exportar seleccionados',
            'export'          => 'Exportar',
        ],

        'bulk-actions' => [
            'deleted' => 'Se eliminaron :count registros de asistencia.',
            'skipped' => 'Se omitieron :count registros (las filas de dispositivos no se pueden eliminar).',
        ],

        'record-actions' => [
            'add-checkout' => 'Añadir salida',
        ],
    ],

    'infolist' => [
        'sections' => [
            'record' => [
                'title' => 'Registro de asistencia',
            ],
            'events' => [
                'title'       => 'Eventos de fichaje',
                'description' => 'Fichajes en bruto emparejados con este registro.',
            ],
        ],

        'entries' => [
            'employee'  => 'Empleado',
            'work-date' => 'Fecha de trabajo',
            'check-in'  => 'Entrada',
            'check-out' => 'Salida',
            'worked'    => 'Trabajado',
            'source'    => 'Fuente',
            'timezone'  => 'Zona horaria del empleado',
        ],

        'events' => [
            'empty'      => 'Aún no hay fichajes vinculados a este registro.',
            'punched-at' => 'Hora del fichaje',
            'direction'  => 'Dirección',
            'source'     => 'Fuente',
            'source-ref' => 'Referencia de origen',
        ],
    ],
];
