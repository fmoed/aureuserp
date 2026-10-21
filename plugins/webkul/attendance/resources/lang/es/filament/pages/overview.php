<?php

return [
    'title' => 'Resumen de asistencia',

    'navigation' => [
        'title' => 'Resumen de asistencia',
    ],

    'form' => [
        'heading'        => 'Filtros',
        'employee'       => 'Empleado',
        'all-employees'  => 'Todos los empleados',
        'date-from'      => 'Desde',
        'date-to'        => 'Hasta',
        'apply'          => 'Aplicar',
        'range-too-wide' => 'El rango no debe superar :days días.',
    ],

    'tabs' => [
        'daily'   => 'Diaria',
        'monthly' => 'Mensual',
    ],

    'table' => [
        'columns' => [
            'date'      => 'Fecha',
            'employee'  => 'Empleado',
            'status'    => 'Estado',
            'check-in'  => 'Entrada',
            'check-out' => 'Salida',
            'minutes'   => 'Trabajado',
        ],

        'empty' => 'Sin días laborables en este rango.',
    ],

    'statuses' => [
        'present'  => 'Presente',
        'absent'   => 'Ausente',
        'pending'  => 'Pendiente',
        'half-day' => 'Medio día',
    ],

    'empty' => [
        'heading' => 'Sin días laborables para mostrar.',
    ],

    'grid' => [
        'legend' => 'A ausente · L de permiso · P pendiente · H festivo · vacío presente.',
    ],

    'export' => 'Informe de ausencias',
];
