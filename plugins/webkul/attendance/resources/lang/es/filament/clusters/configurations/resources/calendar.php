<?php

return [
    'title'      => 'Horario de trabajo (turno)',
    'navigation' => [
        'title' => 'Horarios de trabajo',
    ],

    'actions' => [
        'assign-employees' => [
            'label'           => 'Asignar empleados a este turno',
            'department'      => 'Departamento (opcional)',
            'department-hint' => 'Aplica el turno a todos los empleados de este departamento.',
            'employees'       => 'Empleados (opcional)',
            'employees-hint'  => 'Los empleados seleccionados manualmente prevalecen sobre los filtros de departamento y puesto.',
            'job'             => 'Puesto de trabajo (opcional)',
            'job-hint'        => 'Limita la asignación a los empleados que ocupan este puesto.',
            'preview-label'   => 'Empleados a actualizar',
            'preview-count'   => ':count empleados coinciden con la selección actual.',
            'select-required' => 'Elige un departamento, un puesto o al menos un empleado.',
            'assigned'        => ':count empleados asignados a este turno.',
        ],
    ],

    'table' => [
        'columns' => [
            'employees-count'         => 'Empleados asignados',
            'employees-count-tooltip' => 'Turno predeterminado: :direct asignados directamente, :inherited incluidos automáticamente (sin turno).',
        ],
    ],
];
