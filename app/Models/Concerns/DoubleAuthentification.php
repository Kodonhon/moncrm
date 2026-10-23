<?php

namespace App\Models\Concerns;

/*
 * Double authentification de Breezy (jeffgreco13/filament-breezy) sur le compte utilisateur.
 * Déclaré seulement si le paquet est installé : sans lui, le CRM fonctionne toujours (trait vide).
 */
if (trait_exists(\Jeffgreco13\FilamentBreezy\Traits\TwoFactorAuthenticatable::class)) {
    trait DoubleAuthentification
    {
        use \Jeffgreco13\FilamentBreezy\Traits\TwoFactorAuthenticatable;
    }
} else {
    trait DoubleAuthentification
    {
    }
}
