<?php

return [
    'title'      => 'Jornada de trabalho (turno)',
    'navigation' => [
        'title' => 'Jornadas de trabalho',
    ],

    'actions' => [
        'assign-employees' => [
            'label'           => 'Atribuir funcionários a este turno',
            'department'      => 'Departamento (opcional)',
            'department-hint' => 'Aplica o turno a todos os funcionários deste departamento.',
            'employees'       => 'Funcionários (opcional)',
            'employees-hint'  => 'Funcionários selecionados manualmente substituem os filtros de departamento e cargo.',
            'job'             => 'Cargo (opcional)',
            'job-hint'        => 'Limita a atribuição aos funcionários que ocupam este cargo.',
            'preview-label'   => 'Funcionários a atualizar',
            'preview-count'   => ':count funcionários correspondem à seleção atual.',
            'select-required' => 'Escolha um departamento, um cargo ou pelo menos um funcionário.',
            'assigned'        => ':count funcionários atribuídos a este turno.',
        ],
    ],

    'table' => [
        'columns' => [
            'employees-count'         => 'Funcionários atribuídos',
            'employees-count-tooltip' => 'Jornada padrão: :direct atribuídos diretamente, :inherited incluídos automaticamente (sem jornada).',
        ],
    ],
];
