<?php

return [
    'columns' => [
        'employee' => 'Employé',
        'date'     => 'Date',
        'day'      => 'Jour',
        'status'   => 'Statut',
        'minutes'  => 'Minutes',
    ],

    'notification' => [
        'completed'   => ':count lignes exportées.',
        'failed'      => ':count lignes en échec.',
        'cap-title'   => 'Export trop volumineux',
        'cap-reached' => 'La demande a produit :count lignes, au-dessus de la limite de :limit lignes. Réduisez la plage de dates ou filtrez sur un employé.',
    ],
];
