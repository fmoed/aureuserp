<?php

return [
    'navigation' => [
        'title' => 'Produtos',
    ],

    'label'        => 'Produto',
    'plural-label' => 'Produtos',

    'form' => [
        'sections' => [
            'general' => [
                'title'  => 'Informações Gerais',
                'fields' => [
                    'name'                 => 'Nome do Produto',
                    'category'             => 'Categoria',
                    'type'                 => 'Tipo de Produto',
                    'tracking-mode'        => 'Modo de Rastreamento',
                    'type-asset-hint'      => 'Uma unidade rastreada identificada por número de série e responsável.',
                    'type-consumable-hint' => 'Um lote de quantidade emitido por unidades sem controle de devolução.',
                    'model'                => 'Modelo',
                    'cost'                 => 'Custo Padrão',
                    'is-active'            => 'Ativo',
                    'note'                 => 'Descrição / Especificações',
                ],
            ],

            'settings' => [
                'title'  => 'Configurações',
                'fields' => [
                    'company' => 'Empresa',
                ],
            ],
        ],
    ],

    'infolist' => [
        'sections' => [
            'general' => [
                'title'   => 'Informações Gerais',
                'entries' => [
                    'name'          => 'Nome do Produto',
                    'category'      => 'Categoria',
                    'type'          => 'Tipo de Produto',
                    'tracking-mode' => 'Modo de Rastreamento',
                    'model'         => 'Modelo',
                    'cost'          => 'Custo Padrão',
                    'is-active'     => 'Ativo',
                    'note'          => 'Descrição / Especificações',
                ],
            ],

            'settings' => [
                'title'   => 'Configurações',
                'entries' => [
                    'company' => 'Empresa',
                ],
            ],

            'audit' => [
                'title'   => 'Informações de Auditoria',
                'entries' => [
                    'creator'    => 'Criado por',
                    'created-at' => 'Criado em',
                    'updated-at' => 'Atualizado em',
                ],
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'name'                    => 'Nome do Produto',
            'category'                => 'Categoria',
            'type'                    => 'Tipo de Produto',
            'tracking-mode'           => 'Modo de Rastreamento',
            'model'                   => 'Modelo',
            'cost'                    => 'Custo',
            'assets-count'            => 'Ativos Registrados',
            'available-assets-count'  => 'Disponível no armazém',
            'held-assets-count'       => 'Distribuído em custódia',
            'terminated-assets-count' => 'Descartado / Perdido',
            'total-cost'              => 'Avaliação total',
            'is-active'               => 'Ativo',
            'company'                 => 'Empresa',
            'deleted-at'              => 'Arquivado em',
            'created-at'              => 'Criado em',
            'updated-at'              => 'Atualizado em',
        ],

        'groups' => [
            'category' => 'Categoria',
        ],

        'filters' => [
            'category'      => 'Categoria',
            'type'          => 'Tipo de Produto',
            'tracking-mode' => 'Modo de Rastreamento',
            'is-active'     => 'Ativo',
            'company'       => 'Empresa',
        ],
    ],

    'views' => [
        'active'          => 'Produtos Ativos',
        'durable-assets'  => 'Ativos Duráveis',
        'quantity-assets' => 'Ativos por Quantidade',
        'consumables'     => 'Consumíveis',
        'archived'        => 'Produtos Arquivados',
    ],

    'validation' => [
        'cannot-change-tracking-mode-with-assets' => 'O modo de rastreamento não pode ser alterado após o registro de ativos para este produto.',
    ],

    'relation-manager' => [
        'assets' => [
            'title'   => 'Unidades de Ativos Registradas',
            'actions' => [
                'open' => 'Visualizar Ativo',
            ],
        ],
    ],
];
