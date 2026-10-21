<?php

return [
    'title' => 'Registro de asistencia',

    'navigation' => [
        'title' => 'Registro de asistencia',
    ],

    'form' => [
        'heading'        => 'Filtros del registro',
        'employee'       => 'Empleado',
        'all-employees'  => 'Todos los empleados',
        'view-mode'      => 'Intervalo de tiempo',
        'mode-month'     => 'Mes',
        'mode-range'     => 'Periodo personalizado',
        'mode-year'      => 'Año',
        'month'          => 'Mes',
        'year'           => 'Año',
        'year-hint'      => 'Datos disponibles de los últimos dos años.',
        'date-from'      => 'Desde',
        'date-to'        => 'Hasta',
        'range-hint'     => 'Máximo :days días.',
        'apply'          => 'Aplicar',
        'reset'          => 'Restablecer',
        'range-too-wide' => 'El rango no debe superar :days días.',
        'month-future'   => 'El mes seleccionado está en el futuro.',
    ],

    'filter-chips' => [
        'period'   => 'Período',
        'employee' => 'Empleado',
        'clear'    => 'Limpiar',
    ],

    'table' => [
        'columns' => [
            'employee' => 'Empleado',
            'schedule' => 'Horario de trabajo',
            'rate'     => 'Tasa de ausencias',
            'workdays' => 'Días laborables',
        ],

        'empty' => 'Sin días laborables en este rango. Los empleados sin horario no aparecen.',

        'view' => [
            'totals' => 'Resumen por empleado',
            'days'   => 'Registro diario',
        ],
    ],

    'statuses' => [
        'short' => [
            'present'    => 'P',
            'absent'     => 'A',
            'on-leave'   => 'L',
            'half-day'   => '½',
            'pending'    => '?',
            'holiday'    => 'H',
            'weekly-off' => 'W',
            'future'     => '',
        ],
        'present'    => 'Presente',
        'absent'     => 'Ausente',
        'on-leave'   => 'De permiso',
        'pending'    => 'Pendiente',
        'holiday'    => 'Festivo',
        'half-day'   => 'Medio día',
        'weekly-off' => 'No laborable',
        'future'     => 'Próximo',
    ],

    'empty' => [
        'heading' => 'Sin días laborables para mostrar.',
    ],

    'grid' => [
        'summary'      => 'Resumen',
        'pending-note' => 'El permiso pendiente cuenta como ausencia hasta su aprobación.',
        'chart'        => 'Presentes y ausentes por día',
        'legend'       => 'Guía de estados',
        'totals'       => [
            'employees' => 'Empleados',
            'present'   => 'Días presentes',
            'absent'    => 'Días ausentes',
            'on-leave'  => 'Días de permiso',
            'pending'   => 'Pendiente',
            'holidays'  => 'Festivos',
            'rate'      => 'Tasa de ausencias',
        ],
    ],

    'export' => 'Resumen de ausencias (una fila por empleado)',

    'export-detail' => 'Detalle diario (una fila por día laborable)',
];
