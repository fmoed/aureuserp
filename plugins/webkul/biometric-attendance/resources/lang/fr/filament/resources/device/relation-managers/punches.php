<?php

return [
    'title' => 'Pointages',

    'table' => [
        'columns' => [
            'user-id'    => "ID utilisateur de l'appareil",
            'employee'   => 'Employé',
            'unmatched'  => 'Non apparié',
            'punch-time' => 'Heure du pointage',
            'raw-type'   => 'Type brut',
            'pushed-at'  => 'Envoyé le',
            'pending'    => 'En attente',
        ],

        'filters' => [
            'match-status' => 'Appairage',
            'unmatched'    => 'Pointages non appariés',
            'matched'      => 'Pointages appariés',
            'push-status'  => "Statut d'envoi",
            'pushed'       => 'Envoyé',
        ],

        'empty' => 'Aucun pointage enregistré pour cet appareil pour le moment.',
    ],

    'actions' => [
        'rematch' => [
            'label'     => 'Réapparier les pointages non appariés',
            'completed' => ':count pointages appariés aux employés.',
        ],

        'assign' => [
            'label'     => 'Affecter à un employé',
            'employee'  => 'Employé',
            'assigned'  => 'Code-barres enregistré pour :name et :count pointages historiques récupérés.',
        ],
    ],
];
