<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Filament\Resources\Deposits\Widgets\IndicateursDeposits;
use App\Models\PaiementContrat;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/** Deposit : indicateurs en tête, onglets par statut et par rattachement au CRM. */
class ListeDeposits extends ListRecords
{
    protected static string $resource = DepositResource::class;

    public function getSubheading(): ?string
    {
        return 'Paiements des contrats UPYA, repris de la base des ventes (lecture seule).';
    }

    protected function getHeaderWidgets(): array
    {
        return [IndicateursDeposits::class];
    }

    /** N° de contrat tels qu'écrits dans la base des ventes, pour ceux rattachés à un client du CRM. */
    public static function contratsRattaches(): array
    {
        // Base des ventes distante (~1 s par requête) : liste gardée 5 min
        return Cache::remember('ventes-contrats-rattaches', now()->addMinutes(5), function () {
            $connus = PaiementContrat::clientsParContrat();

            return PaiementContrat::query()->pluck('num_contrat_upya')
                ->filter(fn ($numero) => isset($connus[PaiementContrat::normaliser($numero)]))
                ->values()->all();
        });
    }

    /** Les compteurs des onglets en une seule requête, gardés 5 min. */
    public static function compteurs(): array
    {
        return Cache::remember('ventes-compteurs-onglets', now()->addMinutes(5), fn () => (array) PaiementContrat::query()->toBase()->selectRaw("
            count(*) as tous,
            count(*) filter (where statut_amt = 'full_deposit') as complet,
            count(*) filter (where statut_amt = 'partial_deposit') as partiel
        ")->first() + ['crm' => count(self::contratsRattaches())]);
    }

    public function getTabs(): array
    {
        $compte = fn (string $onglet) => fn () => number_format((int) (self::compteurs()[$onglet] ?? 0), 0, ',', ' ');
        $complet = fn (Builder $q) => $q->where('statut_amt', 'full_deposit');
        $partiel = fn (Builder $q) => $q->where('statut_amt', 'partial_deposit');
        $rattache = fn (Builder $q) => $q->whereIn('num_contrat_upya', self::contratsRattaches());

        return [
            'tous' => Tab::make('Tous')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->badge($compte('tous')),
            'complet' => Tab::make('Dépôt complet')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->badge($compte('complet'))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $complet($query)),
            'partiel' => Tab::make('Dépôt partiel')
                ->icon(Heroicon::OutlinedClock)
                ->badge($compte('partiel'))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $partiel($query)),
            'crm' => Tab::make('Clients du CRM')
                ->icon(Heroicon::OutlinedLink)
                ->badge($compte('crm'))
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $rattache($query)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
