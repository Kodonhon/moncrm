<?php

namespace App\Filament\Resources\Installations\Pages;

use App\Filament\Resources\Installations\InstallationResource;
use App\Filament\Resources\Installations\Widgets\IndicateursInstallations;
use App\Models\Installation;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/** Installations : indicateurs en tête, onglets Installés / Non installés. */
class ListeInstallations extends ListRecords
{
    protected static string $resource = InstallationResource::class;

    public function getSubheading(): ?string
    {
        return 'Rapports des techniciens : systèmes installés et installations non abouties.';
    }

    protected function getHeaderWidgets(): array
    {
        return [IndicateursInstallations::class];
    }

    public function getTabs(): array
    {
        // Les quatre compteurs en une requête, gardés 5 min (les rapports arrivent par import)
        $compteurs = fn () => \Illuminate\Support\Facades\Cache::remember('installations-compteurs-onglets', now()->addMinutes(5),
            fn () => (array) Installation::query()->toBase()->selectRaw('
                count(*) as tous,
                sum(case when systeme_installe = 1 then 1 else 0 end) as installes,
                sum(case when systeme_installe = 0 then 1 else 0 end) as non_installes,
                sum(case when id_client is null then 1 else 0 end) as sans_client
            ')->first());
        $compte = fn (string $cle = 'tous') => fn () => number_format((int) ($compteurs()[$cle] ?? 0), 0, ',', ' ');

        return [
            'tous' => Tab::make('Tous')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->badge($compte()),
            'installes' => Tab::make('Installés')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->badge($compte(cle: 'installes'))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('systeme_installe', 1)),
            'non_installes' => Tab::make('Non installés')
                ->icon(Heroicon::OutlinedXCircle)
                ->badge($compte(cle: 'non_installes'))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('systeme_installe', 0)),
            'sans_client' => Tab::make('Client introuvable')
                ->icon(Heroicon::OutlinedQuestionMarkCircle)
                ->badge($compte(cle: 'sans_client'))
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('id_client')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
