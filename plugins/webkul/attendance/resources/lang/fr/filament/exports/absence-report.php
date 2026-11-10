<?php

return [
    'columns' => [
        'employee'             => 'Employé',
        'workdays'             => 'Jours ouvrés totaux',
        'present'              => 'Jours présents',
        'absent'               => 'Jours absents',
        'absence-rate'         => "Taux d'absentéisme",
        'on-leave'             => 'Jours de congé',
        'holidays'             => 'Jours fériés',
        'pending'              => 'Congés en attente',
        'minutes'              => 'Minutes travaillées',
        'scheduled-hours'      => 'Heures planifiées',
        'regular-hours'        => 'Heures régulières',
        'overtime-hours'       => 'Heures supplémentaires',
        'break-hours'          => 'Heures de pause',
        'late-minutes'         => 'Minutes de retard',
        'early-leave-minutes'  => 'Minutes de départ anticipé',
        'variance-hours'       => "Écart d'heures",
    ],

    'options' => [
        'department' => 'Département',
        'calendar'   => 'Horaire de travail',
        'employee'   => 'Employé',
        'date-from'  => 'Du',
        'date-to'    => 'Au',
    ],

    'notification' => [
        'completed' => ':count lignes exportées.',
        'failed'    => ':count lignes en échec.',
    ],
];
