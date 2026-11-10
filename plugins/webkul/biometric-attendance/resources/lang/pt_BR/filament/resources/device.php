<?php

return [
    'title' => 'Dispositivo biométrico',

    'navigation' => [
        'title' => 'Dispositivos biométricos',
        'group' => 'Presença',
    ],

    'form' => [
        'name'                   => 'Nome',
        'company'                => 'Empresa',
        'host'                   => 'Host / IP',
        'host-helper-text'       => 'Endereço IP ou nome de host do dispositivo acessível a partir deste servidor na porta 4370.',
        'port'                   => 'Porta',
        'protocol'               => 'Protocolo',
        'protocol-helper-text'   => 'Use TCP primeiro. Alguns painéis antigos só respondem por UDP.',
        'comm-key'               => 'Chave COMM',
        'comm-key-helper-text'   => 'Senha de conexão da tela do dispositivo: Menu → COMM → PC Connection. Não é a senha de administrador. Deixe 0 se não definida (padrão).',
        'timezone'               => 'Fuso horário do dispositivo',
        'timezone-helper-text'   => 'Fuso horário local do dispositivo. Comece a digitar para pesquisar, por exemplo Asia/Riyadh.',
        'punch-direction-mode'   => 'Direção da batida',
        'punch-direction-modes'  => [
            'auto-toggle'      => 'Automática (direção desconhecida)',
            'strict-direction' => 'Estrita (inferida do tipo de registro)',
            'force-in'         => 'Sempre entrada',
            'force-out'        => 'Sempre saída',
        ],
        'punch-direction-mode-helper-text' => 'Como as batidas enviadas são rotuladas. Automática deixa sem direção e o emparelhamento decide; estrita deduz a direção do tipo de registro; sempre entrada/saída fixa todas as batidas.',
        'active'                           => 'Ativo',
        'sections'                         => [
            'general'    => ['title' => 'Dispositivo e direção da batida'],
            'connection' => ['title' => 'Rede e conexão'],
            'status'     => [
                'title'      => 'Status de sincronização',
                'last-sync'  => 'Última sincronização',
                'last-error' => 'Último erro',
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'name'                    => 'Nome',
            'company'                 => 'Empresa',
            'host'                    => 'Host / IP',
            'port'                    => 'Porta',
            'protocol'                => 'Protocolo',
            'active'                  => 'Ativo',
            'last-sync'               => 'Última sincronização',
            'last-error'              => 'Último erro',
            'created-at'              => 'Criado em',
        ],

        'filters' => [
            'company'           => 'Empresa',
            'active'            => 'Ativo',
        ],

        'actions' => [
            'test-connection' => [
                'label' => 'Testar conexão',

                'notification' => [
                    'title'        => 'Dispositivo acessível',
                    'failed-title' => 'Falha na conexão',
                    'missing-host' => 'Informe primeiro o host/IP do dispositivo.',
                ],
            ],
            'sync-now' => [
                'label' => 'Sincronizar agora',

                'notification' => [
                    'title'         => 'Sincronização concluída',
                    'failed-title'  => 'Falha na sincronização',
                    'partial-title' => 'Sincronização concluída com avisos',
                    'body'          => 'Armazenados :stored, já presentes :skipped, preenchidos :backfilled.',
                    'partial-body'  => 'Armazenados :stored, já presentes :skipped, preenchidos :backfilled, falhas ao enviar :failed.',
                ],
            ],
        ],
    ],
];
