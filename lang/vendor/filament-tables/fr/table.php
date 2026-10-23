<?php

// Compléments à la traduction française de Filament (clés absentes du paquet)
return [
    'result_count' => '{0} Aucun résultat|{1} :count résultat|[2,*] :count résultats',
    'loading' => 'Chargement…',

    'actions' => [
        'reorder_record' => ['label' => 'Déplacer'],
        'move_record_up' => ['label' => 'Monter'],
        'move_record_down' => ['label' => 'Descendre'],
        'toggle_record_content' => ['label' => 'Afficher / masquer le détail'],
    ],

    'reordering' => [
        'position' => 'Position :position sur :total',
        'moved' => 'Déplacé en position :position sur :total',
    ],

    'columns' => [
        'icon' => ['boolean' => ['true' => 'Oui', 'false' => 'Non']],
        'editable' => [
            'saving' => 'Enregistrement…',
            'saved' => 'Enregistré',
            'failure' => 'Échec de l\'enregistrement',
            'unavailable' => 'Indisponible',
        ],
        'select' => [
            'loading_message' => 'Chargement…',
            'no_options_message' => 'Aucun choix disponible.',
            'no_search_results_message' => 'Aucun choix ne correspond à votre recherche.',
            'placeholder' => 'Choisir une option',
            'searching_message' => 'Recherche…',
            'search_prompt' => 'Tapez pour rechercher…',
        ],
    ],

    'column_manager' => [
        'position' => 'Position :position sur :total',
        'actions' => [
            'move_up' => ['label' => 'Monter'],
            'move_down' => ['label' => 'Descendre'],
            'reorder' => ['label' => 'Réordonner'],
        ],
    ],
];
