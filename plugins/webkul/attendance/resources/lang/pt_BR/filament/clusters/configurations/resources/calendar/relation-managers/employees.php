<?php

return [
    'title' => 'Funcionários atribuídos',

    'default-shift-summary' => 'Esta é a jornada padrão: :direct atribuídos diretamente | :inherited incluídos automaticamente (sem jornada) | total real: :total — qualquer funcionário sem jornada entra automaticamente nesta jornada.',
    'direct-shift-summary'  => ':direct funcionários estão atribuídos diretamente a esta jornada.',

    'columns' => [
        'name'       => 'Funcionário',
        'barcode'    => 'Código de barras',
        'department' => 'Departamento',
        'job'        => 'Cargo',
        'time-zone'  => 'Fuso horário',
    ],

    'actions' => [
        'unassign' => [
            'label'       => 'Remover da jornada',
            'description' => 'Este funcionário voltará para a jornada padrão da empresa.',
            'success'     => ':employee foi removido desta jornada.',
        ],
    ],
];
