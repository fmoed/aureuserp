<?php

return [
    'title' => 'Registro de asistencia',

    'navigation' => [
        'title' => 'Registro de asistencia',
    ],

    'form' => [
        'department'      => 'Departamento',
        'all-departments' => 'Todos los departamentos',
        'calendar'        => 'Horario de trabajo',
        'all-calendars'   => 'Todos los horarios',
        'heading'         => 'Filtros del registro',
        'employee'        => 'Empleado',
        'all-employees'   => 'Todos los empleados',
        'view-mode'       => 'Intervalo de tiempo',
        'mode-month'      => 'Mes',
        'mode-range'      => 'Periodo personalizado',
        'mode-year'       => 'Año',
        'month'           => 'Mes',
        'year'            => 'Año',
        'year-hint'       => 'Datos disponibles de los últimos dos años.',
        'date-from'       => 'Desde',
        'date-to'         => 'Hasta',
        'range-hint'      => 'Máximo :days días.',
        'apply'           => 'Aplicar',
        'reset'           => 'Restablecer',
        'range-too-wide'  => 'El rango no debe superar :days días.',
        'month-future'    => 'El mes seleccionado está en el futuro.',
    ],

    'filter-chips' => [
        'department' => 'Departamento',
        'calendar'   => 'Horario de trabajo',
        'period'     => 'Período',
        'employee'   => 'Empleado',
        'clear'      => 'Limpiar',
    ],

    'table' => [
        'columns' => [
            'employee'          => 'Empleado',
            'schedule'          => 'Horario de trabajo',
            'rate'              => 'Tasa de ausencias',
            'workdays'          => 'Días laborables',
            'scheduled-hours'   => 'Horas programadas',
            'worked-hours'      => 'Horas trabajadas',
            'overtime-hours'    => 'Horas extra',
            'late-minutes'      => 'Retraso (min)',
            'variance-hours'    => 'Variación de horas',
            'missing-checkouts' => 'Salidas faltantes',
            'break'             => 'Descanso',
            'late'              => 'Retraso',
            'early-leave'       => 'Salida anticipada',
            'estimated'         => 'Estimado',
        ],

        'empty' => 'Sin días laborables en este rango. Los empleados sin horario no aparecen.',

        'view' => [
            'totals' => 'Resumen por empleado',
            'days'   => 'Registro diario',
        ],
    ],

    'statuses' => [
        'short' => [
            'present'          => 'P',
            'absent'           => 'A',
            'on-leave'         => 'L',
            'half-day'         => '½',
            'pending'          => '?',
            'holiday'          => 'H',
            'weekly-off'       => 'W',
            'missing-checkout' => '!',
            'weekend-present'  => 'W+',
            'holiday-present'  => 'H+',
            'future'           => '',
        ],
        'present'          => 'Presente',
        'absent'           => 'Ausente',
        'on-leave'         => 'De permiso',
        'pending'          => 'Pendiente',
        'holiday'          => 'Festivo',
        'half-day'         => 'Medio día',
        'weekly-off'       => 'No laborable',
        'missing-checkout' => 'Salida faltante',
        'weekend-present'  => 'Trabajo en descanso',
        'holiday-present'  => 'Trabajo en festivo',
        'future'           => 'Próximo',
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
