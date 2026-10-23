<?php

// Compléments à la traduction française de Filament (clés absentes du paquet)
return [
    'select' => [
        'actions' => [
            'clear' => ['label' => 'Effacer'],
            'remove_option' => ['label' => 'Retirer'],
        ],
        'error_message' => 'Impossible de charger les choix.',
        'required_description' => 'Obligatoire',
        'search_label' => 'Rechercher',
    ],

    'toggle_buttons' => [
        'required_description' => 'Obligatoire',
    ],

    'tags_input' => [
        'tag_added' => 'Valeur « :tag » ajoutée',
        'tag_removed' => 'Valeur « :tag » retirée',
    ],

    'repeater' => [
        'reordering' => [
            'item' => 'Élément',
            'position' => 'Position :position sur :total',
            'moved' => 'Déplacé en position :position sur :total',
        ],
        'columns' => [
            'actions' => ['label' => 'Actions'],
            'reorder' => ['label' => 'Réordonner'],
        ],
    ],

    'date_time_picker' => [
        'month_select' => ['label' => 'Mois'],
        'year_input' => ['label' => 'Année'],
        'hour_input' => ['label' => 'Heure'],
        'minute_input' => ['label' => 'Minute'],
        'second_input' => ['label' => 'Seconde'],
    ],
];
