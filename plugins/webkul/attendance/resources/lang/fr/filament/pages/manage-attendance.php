<?php

return [
    'title' => 'Paramètres de présence',

    'navigation' => [
        'title' => 'Paramètres de présence',
    ],

    'sections' => [
        'hours-policies' => [
            'title'       => 'Heures, congés et verrouillage des périodes',
            'description' => "Valeurs par défaut lorsqu'un poste ne définit pas sa propre politique, ainsi que la date de verrouillage de la paie.",
        ],
        'register-scope' => [
            'title'       => 'Portée du registre et des rapports de présence',
            'description' => "Contrôlez quels employés sont inclus dans le registre de présence et les exports d'absence, afin que les employés sans méthode de pointage n'apparaissent pas comme absents.",
        ],
        'governance' => [
            'title'       => 'Règles de présence',
            'description' => "Limites opérationnelles appliquées à l'appairage et à la saisie manuelle.",
        ],
    ],

    'links' => [
        'calendars' => 'Gérer les horaires',
        'devices'   => 'Gérer les appareils biométriques',
    ],

    'form' => [
        'employee-scope' => [
            'label' => 'Employés affichés dans le registre de présence',
        ],

        'count-pending-leave' => [
            'label' => 'Compter les congés en attente comme absence',
            'hint'  => "Une absence non autorisée reste une absence jusqu'à l'approbation du congé.",
        ],

        'duplicate-punch-window' => [
            'label' => 'Fenêtre de pointage en double',
            'hint'  => 'Les pointages dans cette fenêtre du pointage accepté sont traités comme des doubles scans accidentels.',
        ],

        'max-shift-hours' => [
            'label' => 'Heures maximales par poste',
            'hint'  => "Les sorties manuelles et l'appairage biométrique refusent les postes plus longs.",
        ],

        'seconds-suffix' => 'secondes',
        'hours-suffix'   => 'heures',

        'default-missing-checkout' => [
            'label' => 'Politique de sortie manquante par défaut',
            'hint'  => 'Appliquée aux postes qui ne choisissent pas leur propre politique de sortie manquante.',
        ],

        'mandatory-days-policy' => [
            'label' => 'Politique des jours obligatoires',
            'hint'  => "Indique si les jours obligatoires excusent la présence, l'exigent ou bloquent seulement les demandes de congé.",
        ],

        'count-unpaid-leave' => [
            'label' => "Compter les congés non payés dans le taux d'absence",
            'hint'  => "Désactivé, un congé non payé approuvé reste hors du taux d'absence et de la retenue de paie.",
        ],

        'rounding-mode' => [
            'label' => 'Arrondi des minutes',
            'hint'  => 'Arrondit les minutes planifiées et travaillées calculées.',
        ],

        'attendance-lock-date' => [
            'label' => 'Date de verrouillage de la présence',
            'hint'  => "Les lignes jusqu'à cette date ne peuvent plus être modifiées manuellement ni reconstruites automatiquement après validation de la paie.",
        ],

        'employee-scope-options' => [
            'all'                => 'Tous les employés avec un horaire de travail',
            'enrolled_or_active' => "Employés avec un code-barres (ou une méthode de pointage) OU une activité de présence au cours de l'année écoulée (Recommandé)",
            'enrolled'           => 'Uniquement les employés avec un code-barres (ID biométrique) ou une méthode de pointage active',
            'recent_activity'    => "Uniquement les employés avec une activité de présence au cours de l'année écoulée",
        ],

        'employee-scope-descriptions' => [
            'all'                => 'Affiche tous les employés affectés à un horaire de travail, même sans code-barres ni historique de présence.',
            'enrolled_or_active' => 'Inclut les employés ayant un code-barres, inscrits à un plugin de présence installé (biométrique ou distant, par exemple), ou ayant une présence enregistrée au cours des 12 derniers mois.',
            'enrolled'           => 'Inclut uniquement les employés ayant un code-barres sur leur profil ou inscrits à un plugin de présence enregistré.',
            'recent_activity'    => 'Inclut uniquement les employés ayant au moins un enregistrement de présence ou de pointage, toutes sources confondues, au cours des 12 derniers mois.',
        ],
    ],
];
