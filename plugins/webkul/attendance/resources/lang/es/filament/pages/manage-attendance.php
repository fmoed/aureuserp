<?php

return [
    'title' => 'Configuración de asistencia',

    'navigation' => [
        'title' => 'Configuración de asistencia',
    ],

    'sections' => [
        'hours-policies' => [
            'title'       => 'Horas, permisos y bloqueo de períodos',
            'description' => 'Valores predeterminados cuando un turno no define su propia política, además de la fecha de bloqueo de nómina.',
        ],
        'register-scope' => [
            'title'       => 'Ámbito del registro e informes de asistencia',
            'description' => 'Controla qué empleados se incluyen en el registro de asistencia y en las exportaciones de ausencias, para que los empleados sin método de fichaje no aparezcan como ausentes.',
        ],
        'governance' => [
            'title'       => 'Reglas de asistencia',
            'description' => 'Límites operativos aplicados al emparejamiento y la entrada manual.',
        ],
    ],

    'links' => [
        'calendars' => 'Gestionar horarios',
        'devices'   => 'Gestionar dispositivos biométricos',
    ],

    'form' => [
        'employee-scope' => [
            'label' => 'Empleados mostrados en el registro de asistencia',
        ],

        'count-pending-leave' => [
            'label' => 'Contar permisos pendientes como ausencia',
            'hint'  => 'La ausencia no autorizada sigue siendo ausencia hasta que se apruebe el permiso.',
        ],

        'duplicate-punch-window' => [
            'label' => 'Ventana de fichaje duplicado',
            'hint'  => 'Los fichajes dentro de esta ventana del fichaje aceptado se tratan como doble escaneo accidental.',
        ],

        'max-shift-hours' => [
            'label' => 'Horas máximas por turno',
            'hint'  => 'Las salidas manuales y el emparejamiento biométrico rechazan turnos más largos.',
        ],

        'seconds-suffix' => 'segundos',
        'hours-suffix'   => 'horas',

        'default-missing-checkout' => [
            'label' => 'Política predeterminada de salida faltante',
            'hint'  => 'Se aplica a los turnos que no eligen su propia política de salida faltante.',
        ],

        'mandatory-days-policy' => [
            'label' => 'Política de días obligatorios',
            'hint'  => 'Indica si los días obligatorios eximen la asistencia, la exigen o solo bloquean las solicitudes de permiso.',
        ],

        'count-unpaid-leave' => [
            'label' => 'Contar permisos no remunerados en la tasa de ausencia',
            'hint'  => 'Desactivado, un permiso no remunerado aprobado queda fuera de la tasa de ausencia y de la deducción de nómina.',
        ],

        'rounding-mode' => [
            'label' => 'Redondeo de minutos',
            'hint'  => 'Redondea los minutos programados y trabajados calculados.',
        ],

        'attendance-lock-date' => [
            'label' => 'Fecha de bloqueo de asistencia',
            'hint'  => 'Las filas hasta esta fecha no se pueden editar manualmente ni reconstruir automáticamente tras la aprobación de nómina.',
        ],

        'employee-scope-options' => [
            'all'                => 'Todos los empleados con horario de trabajo',
            'enrolled_or_active' => 'Empleados con código de barras (o método de fichaje) O actividad de asistencia en el último año (Recomendado)',
            'enrolled'           => 'Solo empleados con código de barras (ID biométrico) o método de fichaje activo',
            'recent_activity'    => 'Solo empleados con actividad de asistencia en el último año',
        ],

        'employee-scope-descriptions' => [
            'all'                => 'Muestra a todos los empleados asignados a un horario de trabajo, aunque no tengan código de barras ni historial de asistencia.',
            'enrolled_or_active' => 'Incluye empleados con código de barras, inscritos en cualquier plugin de asistencia instalado (por ejemplo, biométrico o remoto), o con asistencia registrada en los últimos 12 meses.',
            'enrolled'           => 'Incluye solo empleados con código de barras en su perfil o inscritos en un plugin de asistencia registrado.',
            'recent_activity'    => 'Incluye solo empleados con al menos un registro de asistencia o fichaje de cualquier fuente en los últimos 12 meses.',
        ],
    ],
];
