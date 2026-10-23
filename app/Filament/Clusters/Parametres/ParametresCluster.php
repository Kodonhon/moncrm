<?php

namespace App\Filament\Clusters\Parametres;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/**
 * Onglet « Paramètres » : gestion des tables de référence (canaux, localités, villes, shops…).
 */
class ParametresCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Paramètres';

    protected static ?string $clusterBreadcrumb = 'Paramètres';

    protected static ?int $navigationSort = 90;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    /** Pas de menu latéral : la page Administration (cartes) et l'en-tête « ← Administration » en tiennent lieu. */
    protected static bool $shouldRegisterSubNavigation = false;

    /**
     * « Paramètres » n'est surligné que hors des écrans qui ont leur propre entrée dans le menu Administration
     * (Utilisateurs, Rôles, Permissions des rôles, Agents).
     */
    public static function getNavigationItemActiveRoutePattern(): string
    {
        $route = (string) request()->route()?->getName();

        return preg_match('/^filament\.admin\.parametres\.(resources\.(utilisateurs|roles|agents)\.|pages\.permissions-roles)/', $route)
            ? 'aucune-route-active'
            : parent::getNavigationItemActiveRoutePattern();
    }
}
