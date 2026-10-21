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
            'linked-row'  => 'Registro vinculado',
            'linked-hint' => 'Vacío significa pendiente de vinculación. Corrija en Asistencias.',
        ],

        'directions' => [
            'unknown' => 'Desconocido',
            'in'      => 'Entrada',
            'out'     => 'Salida',
        ],

        'filters' => [
            'linkage'           => 'Vinculación',
            'linkage-paired'    => 'Vinculado',
            'linkage-unpaired'  => 'Sin vincular',
            'employee'          => 'Empleado',
            'source'            => 'Fuente',
            'punched-at'        => 'Fecha de marcación',
            'date-from'         => 'Desde',
            'date-to'           => 'Hasta',
            'range-too-wide'    => 'El rango no debe superar :days días.',
        ],

        'toolbar-actions' => [
            'export' => 'Exportar',
        ],
    ],
];
