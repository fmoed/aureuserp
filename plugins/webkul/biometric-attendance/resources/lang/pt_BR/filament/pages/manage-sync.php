<?php

return [
    'title' => 'Sincronização biométrica',

    'navigation' => [
        'title' => 'Sincronização biométrica',
    ],

    'sections' => [
        'interval' => 'Intervalo de sincronização',
        'status'   => 'Status da sincronização',
    ],

    'form' => [
        'cadence' => [
            'label'        => 'Intervalo de sincronização',
            'hint'         => 'Com que frequência os dispositivos são lidos. Aplica-se à próxima execução agendada.',
            'devices-link' => 'Abrir dispositivos biométricos',
        ],

        'cadence-options' => [
            'fifteen-minutes' => 'A cada 15 minutos',
            'thirty-minutes'  => 'A cada 30 minutos',
            'hourly'          => 'A cada hora',
            'daily'           => 'Uma vez por dia (meia-noite)',
        ],
    ],

    'info' => [
        'next-run' => [
            'label'   => 'Próxima execução',
            'unknown' => 'Desconhecida — verifique o agendador do servidor.',
        ],

        'last-sync' => [
            'label' => 'Última sincronização bem-sucedida (qualquer dispositivo)',
            'never' => 'Ainda não sincronizado.',
        ],

        'ops-note' => 'As execuções automáticas precisam do agendador do servidor (`schedule:run` a cada minuto, ou `schedule:work` localmente).',
    ],
];
