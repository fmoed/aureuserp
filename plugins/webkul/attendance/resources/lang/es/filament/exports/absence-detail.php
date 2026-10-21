<?php

return [
    'columns' => [
        'employee' => 'Empleado',
        'date'     => 'Fecha',
        'day'      => 'Día',
        'status'   => 'Estado',
        'minutes'  => 'Minutos',
    ],

    'notification' => [
        'completed'   => ':count filas exportadas.',
        'failed'      => ':count filas fallidas.',
        'cap-title'   => 'Exportación demasiado grande',
        'cap-reached' => 'La solicitud produjo :count filas, por encima del límite de :limit filas. Reduzca el rango de fechas o filtre por un empleado.',
    ],
];
