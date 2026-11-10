<?php

return [
    'title' => 'Sincronización biométrica',

    'navigation' => [
        'title' => 'Sincronización biométrica',
    ],

    'sections' => [
        'interval' => 'Intervalo de sincronización',
        'status'   => 'Estado de sincronización',
    ],

    'form' => [
        'cadence' => [
            'label'        => 'Intervalo de sincronización',
            'hint'         => 'Con qué frecuencia se leen los dispositivos. Se aplica en la próxima ejecución programada.',
            'devices-link' => 'Abrir dispositivos biométricos',
        ],

        'cadence-options' => [
            'fifteen-minutes' => 'Cada 15 minutos',
            'thirty-minutes'  => 'Cada 30 minutos',
            'hourly'          => 'Cada hora',
            'daily'           => 'Una vez al día (medianoche)',
        ],
    ],

    'info' => [
        'next-run' => [
            'label'   => 'Próxima ejecución',
            'unknown' => 'Desconocida: revisa el programador del servidor.',
        ],

        'last-sync' => [
            'label' => 'Última sincronización correcta (cualquier dispositivo)',
            'never' => 'Aún no se ha sincronizado.',
        ],

        'ops-note' => 'Las ejecuciones automáticas necesitan el programador del servidor (`schedule:run` cada minuto, o `schedule:work` en local).',
    ],
];
