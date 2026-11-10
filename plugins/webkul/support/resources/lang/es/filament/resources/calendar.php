<?php

return [
    'title' => 'Horarios laborales',

    'navigation' => [
        'title' => 'Horarios laborales',
    ],

    'groups' => [
        'status'     => 'Estado',
        'created-by' => 'Creado por',
        'created-at' => 'Creado el',
        'updated-at' => 'Actualizado el',
    ],

    'form' => [
        'sections' => [
            'general' => [
                'title'  => 'Información general',
                'fields' => [
                    'name'                  => 'Nombre',
                    'schedule-name'         => 'Nombre del horario',
                    'schedule-name-tooltip' => 'Escriba un nombre descriptivo para el horario laboral.',
                    'timezone'              => 'Zona horaria',
                    'timezone-tooltip'      => 'Seleccione la zona horaria del horario laboral.',
                    'company'               => 'Empresa',
                    'shift-type'            => 'Tipo de turno',
                ],
            ],

            'windows' => [
                'title'          => 'Ventanas de entrada/salida y emparejamiento',
                'description'    => 'Ventanas de aceptación que deciden qué fichajes pertenecen a este turno, incluidos los turnos nocturnos que cruzan la medianoche.',
                'minutes-suffix' => 'minutos',
                'overlap-error'  => 'La duración del turno más ambas ventanas de aceptación debe mantenerse por debajo de 24 horas.',
                'fields'         => [
                    'pairing-mode'    => 'Modo de emparejamiento de fichajes',
                    'check-in-before' => 'Ventana de entrada antes del turno',
                    'check-out-after' => 'Ventana de salida después del turno',
                ],
            ],

            'punctuality' => [
                'title'       => 'Puntualidad y llegada anticipada',
                'description' => 'Períodos de gracia y cómo interactúa la llegada anticipada con la salida anticipada.',
                'fields'      => [
                    'late-grace'           => 'Margen de retraso',
                    'early-leave-grace'    => 'Margen de salida anticipada',
                    'early-arrival-policy' => 'Política de llegada anticipada',
                    'max-early-offset'     => 'Compensación máxima de llegada anticipada',
                ],
            ],

            'policies' => [
                'title'       => 'Descansos, festivos, salidas faltantes y horas extra',
                'description' => 'Deducción de descansos, festivos oficiales, fichajes incompletos, umbral de medio día y reglas de horas extra.',
                'fields'      => [
                    'break-policy'             => 'Política de descanso',
                    'break-duration'           => 'Duración del descanso',
                    'break-trigger'            => 'Activación del descanso',
                    'holiday-policy'           => 'Política de festivos',
                    'missing-checkout-policy'  => 'Política de salida faltante',
                    'missing-checkout-inherit' => 'Heredar de la configuración de asistencia',
                    'half-day-threshold'       => 'Umbral de medio día',
                    'absent-threshold'         => 'Umbral de ausencia',
                    'overtime-enabled'         => 'Habilitar horas extra',
                    'min-overtime'             => 'Horas extra mínimas',
                ],
            ],

            'configuration' => [
                'title'  => 'Configuración de horas de trabajo',
                'fields' => [
                    'hours-per-day'                   => 'Horas por día',
                    'hours-per-day-suffix'            => 'Horas',
                    'full-time-required-hours'        => 'Horas requeridas a tiempo completo',
                    'full-time-required-hours-suffix' => 'Horas por semana',
                ],
            ],

            'flexibility' => [
                'title'  => 'Flexibilidad',
                'fields' => [
                    'status'                     => 'Estado',
                    'two-weeks-calendar'         => 'Calendario de dos semanas',
                    'two-weeks-calendar-tooltip' => 'Habilitar un horario laboral alterno de dos semanas.',
                    'flexible-hours'             => 'Horas flexibles',
                    'flexible-hours-tooltip'     => 'Permitir que los empleados tengan horas de trabajo flexibles.',
                ],
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'id'             => 'ID',
            'name'           => 'Nombre del horario',
            'timezone'       => 'Zona horaria',
            'company'        => 'Empresa',
            'flexible-hours' => 'Horas flexibles',
            'status'         => 'Estado',
            'daily-hours'    => 'Horas diarias',
            'created-by'     => 'Creado por',
            'created-at'     => 'Creado el',
            'updated-at'     => 'Actualizado el',
        ],

        'filters' => [
            'company'                  => 'Empresa',
            'is-active'                => 'Estado',
            'two-week-calendar'        => 'Calendario de dos semanas',
            'flexible-hours'           => 'Horas flexibles',
            'timezone'                 => 'Zona horaria',
            'name'                     => 'Nombre del horario',
            'attendance'               => 'Asistencia',
            'created-by'               => 'Creado por',
            'daily-hours'              => 'Horas diarias',
            'full-time-required-hours' => 'Horas requeridas a tiempo completo',
            'updated-at'               => 'Actualizado el',
            'created-at'               => 'Creado el',
        ],

        'groups' => [
            'name'           => 'Nombre del horario',
            'status'         => 'Estado',
            'timezone'       => 'Zona horaria',
            'flexible-hours' => 'Horas flexibles',
            'daily-hours'    => 'Horas diarias',
            'created-by'     => 'Creado por',
            'created-at'     => 'Creado el',
            'updated-at'     => 'Actualizado el',
        ],

        'actions' => [
            'restore' => [
                'notification' => [
                    'title' => 'Plan de calendario restaurado',
                    'body'  => 'El plan de calendario se ha restaurado correctamente.',
                ],
            ],

            'delete' => [
                'notification' => [
                    'title' => 'Plan de calendario eliminado',
                    'body'  => 'El plan de calendario se ha eliminado correctamente.',
                ],
            ],

            'force-delete' => [
                'notification' => [
                    'title' => 'Plan de calendario eliminado permanentemente',
                    'body'  => 'El plan de calendario se ha eliminado permanentemente correctamente.',
                ],
            ],
        ],

        'bulk-actions' => [
            'restore' => [
                'notification' => [
                    'title' => 'Planes de calendario restaurados',
                    'body'  => 'Los planes de calendario se han restaurado correctamente.',
                ],
            ],

            'delete' => [
                'notification' => [
                    'title' => 'Planes de calendario eliminados',
                    'body'  => 'Los planes de calendario se han eliminado correctamente.',
                ],
            ],

            'force-delete' => [
                'notification' => [
                    'title' => 'Planes de calendario eliminados permanentemente',
                    'body'  => 'Los planes de calendario se han eliminado permanentemente correctamente.',
                ],
            ],
        ],
    ],

    'infolist' => [
        'sections' => [
            'general' => [
                'title'   => 'Información general',
                'entries' => [
                    'name'                  => 'Nombre',
                    'schedule-name'         => 'Nombre del horario',
                    'schedule-name-tooltip' => 'Escriba un nombre descriptivo para el horario laboral.',
                    'timezone'              => 'Zona horaria',
                    'timezone-tooltip'      => 'Seleccione la zona horaria del horario laboral.',
                    'company'               => 'Empresa',
                ],
            ],

            'configuration' => [
                'title'   => 'Configuración de horas de trabajo',
                'entries' => [
                    'hours-per-day'                   => 'Horas por día',
                    'hours-per-day-suffix'            => ' Horas',
                    'full-time-required-hours'        => 'Horas requeridas a tiempo completo',
                    'full-time-required-hours-suffix' => ' Horas por semana',
                ],
            ],

            'flexibility' => [
                'title'   => 'Flexibilidad',
                'entries' => [
                    'status'                     => 'Estado',
                    'two-weeks-calendar'         => 'Calendario de dos semanas',
                    'two-weeks-calendar-tooltip' => 'Habilitar un horario laboral alterno de dos semanas.',
                    'flexible-hours'             => 'Horas flexibles',
                    'flexible-hours-tooltip'     => 'Permitir que los empleados tengan horas de trabajo flexibles.',
                ],
            ],
        ],
    ],
];
