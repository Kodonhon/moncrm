<?php

namespace App\Filament\Pages;

use App\Models\AssignationKit;
use App\Models\PrepInst;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Opérations > Prêts pour l'installation : clients dont le shop manager a assigné le kit (numéros de série),
 * en attente du technicien. Même tableau et mêmes actions que « Assignation des kits » (modifier, annuler).
 * Un client installé (rapport « système installé ») sort de la liste.
 */
class PretsInstallation extends AssignationKits
{
    protected static string $liste = 'pret_installation';

    protected static bool $avecOnglets = false; // liste fixe, sans onglets

    protected static ?string $slug = 'prets-installation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $navigationLabel = 'Prêts pour l\'installation';

    protected static ?string $title = 'Prêts pour l\'installation';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return PrepInst::tableDisponible() && AssignationKit::tableDisponible() && \App\Support\Acces::peut('assignation_kits.voir');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Kit assigné, installation à faire';
    }

    public function getSubheading(): ?string
    {
        return 'Clients dont le kit est assigné (numéros de série) : à installer par le technicien. Ils sortent de la liste une fois installés.';
    }
}
