<?php

return [
    'title' => 'Empleados asignados',

    'default-shift-summary' => 'Este es el turno predeterminado: :direct asignados directamente | :inherited incluidos automáticamente (sin turno) | total real: :total — cualquier empleado sin turno se cuenta automáticamente en este turno.',
    'direct-shift-summary'  => ':direct empleados están asignados directamente a este turno.',

    'columns' => [
        'name'       => 'Empleado',
        'barcode'    => 'Código de barras',
        'department' => 'Departamento',
        'job'        => 'Puesto de trabajo',
        'time-zone'  => 'Zona horaria',
    ],

    'actions' => [
        'unassign' => [
            'label'       => 'Quitar del turno',
            'description' => 'Este empleado volverá al turno predeterminado de la empresa.',
            'success'     => 'Se quitó a :employee de este turno.',
        ],
    ],
];
