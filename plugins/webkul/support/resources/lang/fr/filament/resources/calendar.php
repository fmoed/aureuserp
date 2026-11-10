<?php

return [
    'title' => 'Plannings de travail',

    'navigation' => [
        'title' => 'Plannings de travail',
    ],

    'groups' => [
        'status'     => 'Statut',
        'created-by' => 'Créé par',
        'created-at' => 'Créé le',
        'updated-at' => 'Mis à jour le',
    ],

    'form' => [
        'sections' => [
            'general' => [
                'title'  => 'Informations générales',
                'fields' => [
                    'name'                  => 'Nom',
                    'schedule-name'         => 'Nom du planning',
                    'schedule-name-tooltip' => 'Veuillez saisir un nom descriptif pour le planning de travail.',
                    'timezone'              => 'Fuseau horaire',
                    'timezone-tooltip'      => 'Veuillez sélectionner le fuseau horaire du planning de travail.',
                    'company'               => 'Société',
                    'shift-type'            => 'Type de poste',
                ],
            ],

            'windows' => [
                'title'          => "Fenêtres d'entrée / sortie et appairage",
                'description'    => "Fenêtres d'acceptation qui déterminent les pointages rattachés à ce poste, y compris les postes de nuit qui franchissent minuit.",
                'minutes-suffix' => 'minutes',
                'overlap-error'  => "La durée du poste et les deux fenêtres d'acceptation doivent rester sous 24 heures.",
                'fields'         => [
                    'pairing-mode'    => "Mode d'appairage des pointages",
                    'check-in-before' => "Fenêtre d'entrée avant le poste",
                    'check-out-after' => 'Fenêtre de sortie après le poste',
                ],
            ],

            'punctuality' => [
                'title'       => 'Ponctualité et arrivée anticipée',
                'description' => "Périodes de grâce et interaction entre l'arrivée anticipée et le départ anticipé.",
                'fields'      => [
                    'late-grace'           => 'Tolérance de retard',
                    'early-leave-grace'    => 'Tolérance de départ anticipé',
                    'early-arrival-policy' => "Politique d'arrivée anticipée",
                    'max-early-offset'     => "Décalage maximal d'arrivée anticipée",
                ],
            ],

            'policies' => [
                'title'       => 'Pauses, jours fériés, sorties manquantes et heures supplémentaires',
                'description' => "Déduction des pauses, jours fériés, pointages incomplets, seuil de demi-journée et règles d'heures supplémentaires.",
                'fields'      => [
                    'break-policy'             => 'Politique de pause',
                    'break-duration'           => 'Durée de la pause',
                    'break-trigger'            => 'Déclencheur de pause',
                    'holiday-policy'           => 'Politique des jours fériés',
                    'missing-checkout-policy'  => 'Politique de sortie manquante',
                    'missing-checkout-inherit' => 'Hériter des paramètres de présence',
                    'half-day-threshold'       => 'Seuil de demi-journée',
                    'absent-threshold'         => "Seuil d'absence",
                    'overtime-enabled'         => 'Activer les heures supplémentaires',
                    'min-overtime'             => 'Heures supplémentaires minimales',
                ],
            ],

            'configuration' => [
                'title'  => 'Configuration des heures de travail',
                'fields' => [
                    'hours-per-day'                   => 'Heures par jour',
                    'hours-per-day-suffix'            => 'Heures',
                    'full-time-required-hours'        => 'Heures requises à temps plein',
                    'full-time-required-hours-suffix' => 'Heures par semaine',
                ],
            ],

            'flexibility' => [
                'title'  => 'Flexibilité',
                'fields' => [
                    'status'                     => 'Statut',
                    'two-weeks-calendar'         => 'Calendrier sur deux semaines',
                    'two-weeks-calendar-tooltip' => 'Activer un planning de travail alterné sur deux semaines.',
                    'flexible-hours'             => 'Horaires flexibles',
                    'flexible-hours-tooltip'     => 'Permettre aux employés de bénéficier d\'horaires de travail flexibles.',
                ],
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'id'             => 'ID',
            'name'           => 'Nom du planning',
            'timezone'       => 'Fuseau horaire',
            'company'        => 'Société',
            'flexible-hours' => 'Horaires flexibles',
            'status'         => 'Statut',
            'daily-hours'    => 'Heures quotidiennes',
            'created-by'     => 'Créé par',
            'created-at'     => 'Créé le',
            'updated-at'     => 'Mis à jour le',
        ],

        'filters' => [
            'company'                  => 'Société',
            'is-active'                => 'Statut',
            'two-week-calendar'        => 'Calendrier sur deux semaines',
            'flexible-hours'           => 'Horaires flexibles',
            'timezone'                 => 'Fuseau horaire',
            'name'                     => 'Nom du planning',
            'attendance'               => 'Présence',
            'created-by'               => 'Créé par',
            'daily-hours'              => 'Heures quotidiennes',
            'full-time-required-hours' => 'Heures requises à temps plein',
            'updated-at'               => 'Mis à jour le',
            'created-at'               => 'Créé le',
        ],

        'groups' => [
            'name'           => 'Nom du planning',
            'status'         => 'Statut',
            'timezone'       => 'Fuseau horaire',
            'flexible-hours' => 'Horaires flexibles',
            'daily-hours'    => 'Heures quotidiennes',
            'created-by'     => 'Créé par',
            'created-at'     => 'Créé le',
            'updated-at'     => 'Mis à jour le',
        ],

        'actions' => [
            'restore' => [
                'notification' => [
                    'title' => 'Plan de calendrier restauré',
                    'body'  => 'Le plan de calendrier a été restauré avec succès.',
                ],
            ],

            'delete' => [
                'notification' => [
                    'title' => 'Plan de calendrier supprimé',
                    'body'  => 'Le plan de calendrier a été supprimé avec succès.',
                ],
            ],

            'force-delete' => [
                'notification' => [
                    'title' => 'Plan de calendrier définitivement supprimé',
                    'body'  => 'Le plan de calendrier a été définitivement supprimé avec succès.',
                ],
            ],
        ],

        'bulk-actions' => [
            'restore' => [
                'notification' => [
                    'title' => 'Plans de calendrier restaurés',
                    'body'  => 'Les plans de calendrier ont été restaurés avec succès.',
                ],
            ],

            'delete' => [
                'notification' => [
                    'title' => 'Plans de calendrier supprimés',
                    'body'  => 'Les plans de calendrier ont été supprimés avec succès.',
                ],
            ],

            'force-delete' => [
                'notification' => [
                    'title' => 'Plans de calendrier définitivement supprimés',
                    'body'  => 'Les plans de calendrier ont été définitivement supprimés avec succès.',
                ],
            ],
        ],
    ],

    'infolist' => [
        'sections' => [
            'general' => [
                'title'   => 'Informations générales',
                'entries' => [
                    'name'                  => 'Nom',
                    'schedule-name'         => 'Nom du planning',
                    'schedule-name-tooltip' => 'Veuillez saisir un nom descriptif pour le planning de travail.',
                    'timezone'              => 'Fuseau horaire',
                    'timezone-tooltip'      => 'Veuillez sélectionner le fuseau horaire du planning de travail.',
                    'company'               => 'Société',
                ],
            ],

            'configuration' => [
                'title'   => 'Configuration des heures de travail',
                'entries' => [
                    'hours-per-day'                   => 'Heures par jour',
                    'hours-per-day-suffix'            => ' Heures',
                    'full-time-required-hours'        => 'Heures requises à temps plein',
                    'full-time-required-hours-suffix' => ' Heures par semaine',
                ],
            ],

            'flexibility' => [
                'title'   => 'Flexibilité',
                'entries' => [
                    'status'                     => 'Statut',
                    'two-weeks-calendar'         => 'Calendrier sur deux semaines',
                    'two-weeks-calendar-tooltip' => 'Activer un planning de travail alterné sur deux semaines.',
                    'flexible-hours'             => 'Horaires flexibles',
                    'flexible-hours-tooltip'     => 'Permettre aux employés de bénéficier d\'horaires de travail flexibles.',
                ],
            ],
        ],
    ],
];
