<?php

return [
    'columns' => [
        'employee'              => 'Employé',
        'schedule'              => 'Horaire',
        'workdays'              => 'Jours travaillés',
        'present'               => 'Présent',
        'absent'                => 'Absent',
        'on-leave'              => 'Congés',
        'hours'                 => 'Heures travaillées',
        'scheduled-minutes'     => 'Heures planifiées',
        'worked-minutes'        => 'Heures travaillées',
        'overtime-minutes'      => 'Heures supplémentaires',
        'break-minutes'         => 'Heures de pause',
        'late-minutes'          => 'Minutes de retard',
        'early-leave-minutes'   => 'Minutes de départ anticipé',
        'variance-minutes'      => "Écart d'heures",
        'absence-rate'          => 'Taux d’absence',
    ],

    'notification' => [
        'completed'   => ':count lignes exportées.',
        'failed'      => ':count lignes en échec.',
        'cap-title'   => 'Export trop volumineux',
        'cap-reached' => 'La demande a produit :count lignes, au-dessus de la limite de :limit lignes. Réduisez la plage de dates ou filtrez sur un employé.',
    ],
];
