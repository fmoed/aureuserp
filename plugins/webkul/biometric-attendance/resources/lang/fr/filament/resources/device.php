<?php

return [
    'title' => 'Appareil biométrique',

    'navigation' => [
        'title' => 'Appareils biométriques',
        'group' => 'Présence',
    ],

    'form' => [
        'name'                   => 'Nom',
        'company'                => 'Société',
        'host'                   => 'Hôte / IP',
        'host-helper-text'       => 'Adresse IP ou nom d’hôte de l’appareil accessible depuis ce serveur sur le port 4370.',
        'port'                   => 'Port',
        'protocol'               => 'Protocole',
        'protocol-helper-text'   => 'Utilisez TCP en premier. Certains anciens panneaux ne répondent qu’en UDP.',
        'comm-key'               => 'Clé COMM',
        'comm-key-helper-text'   => 'Mot de passe de connexion depuis l’écran de l’appareil : Menu → COMM → PC Connection. Ce n’est pas le mot de passe administrateur. Laissez 0 s’il n’est pas défini (par défaut).',
        'timezone'               => 'Fuseau horaire de l’appareil',
        'timezone-helper-text'   => 'Fuseau horaire local de l’appareil. Commencez à taper pour rechercher, par ex. Asia/Riyadh.',
        'punch-direction-mode'   => 'Sens du pointage',
        'punch-direction-modes'  => [
            'auto-toggle'      => 'Automatique (sens inconnu)',
            'strict-direction' => 'Strict (déduit du type d’enregistrement)',
            'force-in'         => 'Toujours entrée',
            'force-out'        => 'Toujours sortie',
        ],
        'punch-direction-mode-helper-text' => 'Comment les pointages envoyés sont étiquetés. Automatique les laisse sans sens et l’appariement décide ; strict déduit le sens du type d’enregistrement ; toujours entrée/sortie fixe chaque pointage.',
        'active'                           => 'Actif',
        'sections'                         => [
            'general'    => ['title' => 'Appareil et sens du pointage'],
            'connection' => ['title' => 'Réseau et connexion'],
            'status'     => [
                'title'      => 'État de synchronisation',
                'last-sync'  => 'Dernière synchronisation',
                'last-error' => 'Dernière erreur',
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'name'                    => 'Nom',
            'company'                 => 'Société',
            'host'                    => 'Hôte / IP',
            'port'                    => 'Port',
            'protocol'                => 'Protocole',
            'active'                  => 'Actif',
            'last-sync'               => 'Dernière synchronisation',
            'last-error'              => 'Dernière erreur',
            'created-at'              => 'Créé le',
        ],

        'filters' => [
            'company'           => 'Société',
            'active'            => 'Actif',
        ],

        'actions' => [
            'test-connection' => [
                'label' => 'Tester la connexion',

                'notification' => [
                    'title'        => 'Appareil accessible',
                    'failed-title' => 'Échec de la connexion',
                    'missing-host' => 'Saisissez d’abord l’hôte/IP de l’appareil.',
                ],
            ],
            'sync-now' => [
                'label' => 'Synchroniser maintenant',

                'notification' => [
                    'title'         => 'Synchronisation terminée',
                    'failed-title'  => 'Échec de la synchronisation',
                    'partial-title' => 'Synchronisation terminée avec des avertissements',
                    'body'          => 'Enregistrés :stored, déjà présents :skipped, récupérés :backfilled.',
                    'partial-body'  => 'Enregistrés :stored, déjà présents :skipped, récupérés :backfilled, échecs d\'envoi :failed.',
                ],
            ],
        ],
    ],
];
