<?php

return [
    'title' => 'Eventos de asistencia',

    'navigation' => [
        'title' => 'Eventos de asistencia',
    ],

    'table' => [
        'columns' => [
            'employee'    => 'Empleado',
            'punched-at'  => 'Hora de marcación',
            'direction'   => 'Dirección',
            'source'      => 'Fuente',
            'linked-row'  => 'Fila vinculada',
        ],

        'directions' => [
            'unknown' => 'Desconocido',
            'in'      => 'Entrada',
            'out'     => 'Salida',
        ],

        'filters' => [
            'employee'   => 'Empleado',
            'source'     => 'Fuente',
            'punched-at' => 'Fecha de marcación',
            'date-from'  => 'Desde',
            'date-to'    => 'Hasta',
        ],

        'toolbar-actions' => [
            'export' => 'Exportar',
        ],
    ],
];
