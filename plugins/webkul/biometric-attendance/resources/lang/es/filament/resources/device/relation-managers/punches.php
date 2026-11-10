<?php

return [
    'title' => 'Fichajes',

    'table' => [
        'columns' => [
            'user-id'    => 'ID de usuario del dispositivo',
            'employee'   => 'Empleado',
            'unmatched'  => 'Sin emparejar',
            'punch-time' => 'Hora del fichaje',
            'raw-type'   => 'Tipo bruto',
            'pushed-at'  => 'Enviado el',
            'pending'    => 'Pendiente',
        ],

        'filters' => [
            'match-status' => 'Emparejamiento',
            'unmatched'    => 'Fichajes sin emparejar',
            'matched'      => 'Fichajes emparejados',
            'push-status'  => 'Estado de envío',
            'pushed'       => 'Enviado',
        ],

        'empty' => 'Todavía no hay fichajes registrados para este dispositivo.',
    ],

    'actions' => [
        'rematch' => [
            'label'     => 'Reemparejar fichajes pendientes',
            'completed' => ':count fichajes emparejados con empleados.',
        ],

        'assign' => [
            'label'     => 'Asignar a empleado',
            'employee'  => 'Empleado',
            'assigned'  => 'Código de barras guardado para :name y :count fichajes históricos recuperados.',
        ],
    ],
];
