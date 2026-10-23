<?php

namespace App\Filament\Resources\PreparationInstallations\Pages;

use App\Filament\Resources\PreparationInstallations\PreparationInstallationResource as R;
use App\Models\PrepInst;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/** Préparation installation : onglets selon le résultat du dernier appel du CX. */
class ListePreparationInstallation extends ListRecords
{
    protected static string $resource = R::class;

    public function getSubheading(): ?string
    {
        return 'Clients validés au credit scoring : appel du CX pour préparer l\'installation.';
    }

    public function getTabs(): array
    {
        $onglet = fn (string $etat, string $libelle, $icone, string $couleur) => Tab::make($libelle)
            ->icon($icone)
            // Compteurs recalculés au plus une fois par minute (base distante), pas à chaque tri ou page
            ->badge(fn () => self::compter($etat, fn () => R::filtrerEtat(R::getEloquentQuery(), $etat)->count()))
            ->badgeColor($couleur)
            ->modifyQueryUsing(fn (Builder $query) => R::filtrerEtat($query, $etat));

        // Nouveaux arrivés (jamais appelés) séparés de ceux pour qui une action a déjà été prise
        return [
            'a_appeler' => $onglet('a_appeler', 'Nouveaux · à appeler', Heroicon::OutlinedSparkles, 'info'),
            'a_rappeler' => $onglet('a_rappeler', 'À rappeler', Heroicon::OutlinedPhoneArrowUpRight, 'warning'),
            'pas_pret' => $onglet('pas_pret', 'Pas prêts', Heroicon::OutlinedExclamationTriangle, 'danger'),
            'pret' => $onglet('pret', 'Prêts pour l\'installation', Heroicon::OutlinedCheckBadge, 'success'),
            'resiliation' => $onglet('resiliation', 'Résiliation / annulation', Heroicon::OutlinedXCircle, 'gray'),
            'installe' => $onglet('installe', 'Déjà installés', Heroicon::OutlinedHomeModern, 'info'),
            'tous' => Tab::make('Tous')->badge(fn () => self::compter('tous', fn () => R::getEloquentQuery()->count())),
        ];
    }

    private static function compter(string $onglet, \Closure $calcul): int
    {
        return \Illuminate\Support\Facades\Cache::remember("preparation-onglet:{$onglet}:".(auth()->id() ?? 'console'), now()->addMinute(), $calcul);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
