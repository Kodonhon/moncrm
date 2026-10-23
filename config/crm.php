<?php

return [
    /*
     | Connexion automatique (sans mot de passe) avec le premier utilisateur.
     | Pratique en développement ; à désactiver (CRM_CONNEXION_AUTO=false) dès que les rôles et accès
     | sont installés, pour que chacun se connecte avec son compte et ne voie que ce qui le concerne.
     */
    'connexion_automatique' => env('CRM_CONNEXION_AUTO', true),
];
