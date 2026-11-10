<?php

return [
    'title' => 'Dispositivo biométrico',

    'navigation' => [
        'title' => 'Dispositivos biométricos',
        'group' => 'Asistencia',
    ],

    'form' => [
        'name'                   => 'Nombre',
        'company'                => 'Empresa',
        'host'                   => 'Host / IP',
        'host-helper-text'       => 'Dirección IP o nombre de host del dispositivo accesible desde este servidor en el puerto 4370.',
        'port'                   => 'Puerto',
        'protocol'               => 'Protocolo',
        'protocol-helper-text'   => 'Usa TCP primero. Algunos paneles antiguos solo responden por UDP.',
        'comm-key'               => 'Clave COMM',
        'comm-key-helper-text'   => 'Contraseña de conexión de la pantalla del dispositivo: Menú → COMM → PC Connection. No es la contraseña de administrador. Deja 0 si no está definida (predeterminado).',
        'timezone'               => 'Zona horaria del dispositivo',
        'timezone-helper-text'   => 'Zona horaria local del dispositivo. Empieza a escribir para buscar, p. ej. Asia/Riyadh.',
        'punch-direction-mode'   => 'Dirección del fichaje',
        'punch-direction-modes'  => [
            'auto-toggle'      => 'Automática (dirección desconocida)',
            'strict-direction' => 'Estricta (inferida del tipo de registro)',
            'force-in'         => 'Siempre entrada',
            'force-out'        => 'Siempre salida',
        ],
        'punch-direction-mode-helper-text' => 'Cómo se etiquetan los fichajes enviados. Automática los deja sin dirección y el emparejamiento decide; estricta deduce la dirección del tipo de registro; siempre entrada/salida fija todos los fichajes.',
        'active'                           => 'Activo',
        'sections'                         => [
            'general'    => ['title' => 'Dispositivo y dirección del fichaje'],
            'connection' => ['title' => 'Red y conexión'],
            'status'     => [
                'title'      => 'Estado de sincronización',
                'last-sync'  => 'Última sincronización',
                'last-error' => 'Último error',
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'name'                    => 'Nombre',
            'company'                 => 'Empresa',
            'host'                    => 'Host / IP',
            'port'                    => 'Puerto',
            'protocol'                => 'Protocolo',
            'active'                  => 'Activo',
            'last-sync'               => 'Última sincronización',
            'last-error'              => 'Último error',
            'created-at'              => 'Creado el',
        ],

        'filters' => [
            'company'           => 'Empresa',
            'active'            => 'Activo',
        ],

        'actions' => [
            'test-connection' => [
                'label' => 'Probar conexión',

                'notification' => [
                    'title'        => 'Dispositivo accesible',
                    'failed-title' => 'Error de conexión',
                    'missing-host' => 'Introduce primero el host/IP del dispositivo.',
                ],
            ],
            'sync-now' => [
                'label' => 'Sincronizar ahora',

                'notification' => [
                    'title'         => 'Sincronización completada',
                    'failed-title'  => 'Error de sincronización',
                    'partial-title' => 'Sincronización completada con advertencias',
                    'body'          => 'Guardados :stored, ya presentes :skipped, rellenados :backfilled.',
                    'partial-body'  => 'Guardados :stored, ya presentes :skipped, rellenados :backfilled, fallidos al enviar :failed.',
                ],
            ],
        ],
    ],
];
