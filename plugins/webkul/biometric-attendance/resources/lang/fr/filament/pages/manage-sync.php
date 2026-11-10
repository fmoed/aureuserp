<?php

return [
    'title' => 'Synchronisation biométrique',

    'navigation' => [
        'title' => 'Synchronisation biométrique',
    ],

    'sections' => [
        'interval' => 'Intervalle de synchronisation',
        'status'   => 'État de la synchronisation',
    ],

    'form' => [
        'cadence' => [
            'label'        => 'Intervalle de synchronisation',
            'hint'         => 'Fréquence de lecture des appareils. S’applique à la prochaine exécution planifiée.',
            'devices-link' => 'Ouvrir les appareils biométriques',
        ],

        'cadence-options' => [
            'fifteen-minutes' => 'Toutes les 15 minutes',
            'thirty-minutes'  => 'Toutes les 30 minutes',
            'hourly'          => 'Toutes les heures',
            'daily'           => 'Une fois par jour (minuit)',
        ],
    ],

    'info' => [
        'next-run' => [
            'label'   => 'Prochaine exécution',
            'unknown' => 'Inconnue : vérifiez le planificateur du serveur.',
        ],

        'last-sync' => [
            'label' => 'Dernière synchronisation réussie (tout appareil)',
            'never' => 'Jamais synchronisé.',
        ],

        'ops-note' => 'Les exécutions automatiques nécessitent le planificateur du serveur (`schedule:run` chaque minute, ou `schedule:work` en local).',
    ],
];
