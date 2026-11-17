<?php

return [
    'title'    => 'Actif de l\'Entreprise',
    'category' => [
        'circular-reference' => 'Une catégorie ne peut pas être son propre ancêtre ni créer de référence circulaire.',
    ],
    'deletion' => [
        'held'                  => 'Impossible de supprimer un actif actuellement en garde. Veuillez le retourner, le transférer, le mettre au rebut ou le marquer comme perdu d\'abord.',
        'available-has-history' => 'Impossible d\'archiver ou de supprimer un actif disponible ayant un historique de garde. Mettez-le au rebut ou marquez-le comme perdu d\'abord.',
        'has-history'           => 'Impossible de supprimer définitivement un actif ayant un historique de garde.',
        'category-referenced'   => 'Impossible de supprimer une catégorie qui contient des sous-catégories ou des produits d\'actifs associés.',
        'product-in-use'        => 'Impossible de supprimer ou de désactiver le produit :product car il est référencé par des unités d\'actifs.',
    ],
    'company'  => [
        'immutable' => 'Impossible de modifier la société d\'un actif en garde ou ayant un historique.',
    ],
    'product-type-immutable'       => 'Le type de produit ne peut pas être modifié car des enregistrements d\'actifs ou de lots existent déjà pour ce produit.',
    'terminal-status-irreversible' => 'Les statuts finaux d\'un actif ne peuvent pas être annulés directement.',
    'product-immutable'            => 'Le produit de l\'actif ne peut pas être modifié après l\'enregistrement de mouvements opérationnels.',
    'cost-immutable'               => 'Le coût historique d\'acquisition ne peut pas être modifié une fois que des mouvements opérationnels existent.',
    'initial-quantity-immutable'   => 'La quantité initiale enregistrée ne peut pas être modifiée une fois que des mouvements opérationnels existent.',
];
