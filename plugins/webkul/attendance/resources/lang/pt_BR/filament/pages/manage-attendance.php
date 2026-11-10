<?php

return [
    'title' => 'Configurações de presença',

    'navigation' => [
        'title' => 'Configurações de presença',
    ],

    'sections' => [
        'hours-policies' => [
            'title'       => 'Horas, licenças e bloqueio de períodos',
            'description' => 'Padrões usados quando o turno não define a própria política, além da data de bloqueio da folha.',
        ],
        'register-scope' => [
            'title'       => 'Escopo do registro e relatórios de presença',
            'description' => 'Controla quais funcionários são incluídos no registro de presença e nas exportações de ausência, para que funcionários sem método de registro não apareçam como ausentes.',
        ],
        'governance' => [
            'title'       => 'Regras de presença',
            'description' => 'Limites operacionais aplicados ao pareamento e ao lançamento manual.',
        ],
    ],

    'links' => [
        'calendars' => 'Gerenciar jornadas',
        'devices'   => 'Gerenciar dispositivos biométricos',
    ],

    'form' => [
        'employee-scope' => [
            'label' => 'Funcionários exibidos no registro de presença',
        ],

        'count-pending-leave' => [
            'label' => 'Contar licenças pendentes como ausência',
            'hint'  => 'A ausência não autorizada continua sendo ausência até a licença ser aprovada.',
        ],

        'duplicate-punch-window' => [
            'label' => 'Janela de batida duplicada',
            'hint'  => 'Batidas dentro desta janela da batida aceita são tratadas como leitura dupla acidental.',
        ],

        'max-shift-hours' => [
            'label' => 'Horas máximas por turno',
            'hint'  => 'Saídas manuais e o pareamento biométrico recusam turnos mais longos.',
        ],

        'seconds-suffix' => 'segundos',
        'hours-suffix'   => 'horas',

        'default-missing-checkout' => [
            'label' => 'Política padrão de saída ausente',
            'hint'  => 'Aplicada aos turnos que não escolhem a própria política de saída ausente.',
        ],

        'mandatory-days-policy' => [
            'label' => 'Política de dias obrigatórios',
            'hint'  => 'Indica se os dias obrigatórios dispensam a presença, a exigem ou apenas bloqueiam solicitações de licença.',
        ],

        'count-unpaid-leave' => [
            'label' => 'Contar licenças não remuneradas na taxa de ausência',
            'hint'  => 'Desativado, uma licença não remunerada aprovada fica fora da taxa de ausência e do desconto na folha.',
        ],

        'rounding-mode' => [
            'label' => 'Arredondamento de minutos',
            'hint'  => 'Arredonda os minutos programados e trabalhados calculados.',
        ],

        'attendance-lock-date' => [
            'label' => 'Data de bloqueio de presença',
            'hint'  => 'Linhas até esta data não podem ser editadas manualmente nem reconstruídas automaticamente após a aprovação da folha.',
        ],

        'employee-scope-options' => [
            'all'                => 'Todos os funcionários com jornada de trabalho',
            'enrolled_or_active' => 'Funcionários com código de barras (ou método de registro) OU atividade de presença no último ano (Recomendado)',
            'enrolled'           => 'Somente funcionários com código de barras (ID biométrico) ou método de registro ativo',
            'recent_activity'    => 'Somente funcionários com atividade de presença no último ano',
        ],

        'employee-scope-descriptions' => [
            'all'                => 'Mostra todos os funcionários atribuídos a uma jornada de trabalho, mesmo sem código de barras ou histórico de presença.',
            'enrolled_or_active' => 'Inclui funcionários com código de barras, inscritos em qualquer plugin de presença instalado (por exemplo, biométrico ou remoto), ou com presença registrada nos últimos 12 meses.',
            'enrolled'           => 'Inclui apenas funcionários com código de barras no perfil ou inscritos em um plugin de presença registrado.',
            'recent_activity'    => 'Inclui apenas funcionários com pelo menos um registro de presença ou batida de qualquer fonte nos últimos 12 meses.',
        ],
    ],
];
