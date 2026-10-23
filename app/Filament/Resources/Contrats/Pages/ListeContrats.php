<?php

namespace App\Filament\Resources\Contrats\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Contrats\ContratResource;
use App\Models\Client;
use App\Models\Contrat;
use App\Support\Acces;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ventes > Contrats, présentée comme « Customer accounts » d'UPYA :
 * raccourcis Ventes du jour / de la semaine / du mois, filtres au-dessus du tableau,
 * boutons Créer, Exporter et Actualiser.
 */
class ListeContrats extends ListRecords
{
    protected static string $resource = ContratResource::class;

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'Comptes clients : un contrat par dimensionnement validé, avec son statut de deposit dans UPYA.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('creer')
                ->label('Créer')
                ->icon(Heroicon::OutlinedPlus)
                ->authorize(fn () => Acces::peut('contrats.creer'))
                ->modalHeading('Créer un contrat')
                ->modalDescription('Le contrat se crée depuis la fiche du client (dimensionnement validé + KYC). Choisissez le client pour ouvrir sa fiche.')
                ->modalSubmitActionLabel('Ouvrir la fiche')
                ->schema([
                    Select::make('id_client')
                        ->label('Client')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Client::query()
                            ->where(fn (Builder $q) => $q
                                ->where('nom_client', 'like', '%'.mb_strtoupper($search).'%')
                                ->orWhere('prenoms_client', 'like', "%{$search}%")
                                ->when(preg_replace('/\D/', '', $search) !== '', fn (Builder $q) => $q
                                    ->orWhere('contact1_client', 'like', '%'.preg_replace('/\D/', '', $search).'%')))
                            ->limit(30)
                            ->get(['id_client', 'nom_client', 'prenoms_client', 'contact1_client'])
                            ->mapWithKeys(fn (Client $c) => [$c->id_client => trim($c->nom_complet.' — '.$c->contact1_client, ' —')])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Client::find($value)?->nom_complet),
                ])
                ->action(fn (array $data) => $this->redirect(ClientResource::getUrl('view', ['record' => $data['id_client']]))),
            Action::make('exporter')
                ->label('Exporter')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->outlined()
                ->authorize(fn () => Acces::peut('contrats.exporter'))
                ->action(fn () => $this->exporter()),
        ];
    }

    /** Raccourcis de période, comme « Sales today / this week / this month ». */
    public function getTabs(): array
    {
        // Compteurs gardés 1 min par utilisateur (périmètre d'accès) : pas recalculés à chaque tri ou page
        $compte = fn (callable $filtre, string $cle = '') => fn () => number_format(Cache::remember(
            'contrats-onglet:'.$cle.':'.(auth()->id() ?? 'console').':'.today()->toDateString(), now()->addMinute(),
            fn () => $filtre(Contrat::query())->count()), 0, ',', ' ');
        $jour = fn (Builder $q) => $q->whereDate('date_contrat', today());
        $semaine = fn (Builder $q) => $q->whereBetween('date_contrat', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        $mois = fn (Builder $q) => $q->whereBetween('date_contrat', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        return [
            'tous' => Tab::make('Tous les contrats')->badge($compte(fn ($q) => $q, 'tous')),
            'jour' => Tab::make('Ventes du jour')->badge($compte($jour, 'jour'))->modifyQueryUsing($jour),
            'semaine' => Tab::make('Ventes de la semaine')->badge($compte($semaine, 'semaine'))->modifyQueryUsing($semaine),
            'mois' => Tab::make('Ventes du mois')->badge($compte($mois, 'mois'))->modifyQueryUsing($mois),
        ];
    }

    /** Bouton « Actualiser » à côté de la recherche : relit les deposits dans la base des ventes. */
    public function actualiser(): void
    {
        foreach (['ventes-deposits-par-numero-v2', 'ventes-injoignable', 'ventes-clients-par-contrat'] as $cle) {
            Cache::forget($cle);
        }
        $this->resetTable();

        Notification::make()->title('Liste actualisée')->body('Les deposits ont été relus dans UPYA.')->success()->send();
    }

    /** Export CSV (« ; », lisible par Excel) des contrats affichés, avec les filtres en cours, valeurs non masquées. */
    protected function exporter(): StreamedResponse
    {
        $requete = $this->getFilteredSortedTableQuery();

        return response()->streamDownload(function () use ($requete) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            $entetes = false;

            $requete->chunk(1000, function ($contrats) use ($sortie, &$entetes) {
                foreach ($contrats as $contrat) {
                    $ligne = ContratResource::ligneExport($contrat);
                    if (! $entetes) {
                        fputcsv($sortie, array_keys($ligne), ';');
                        $entetes = true;
                    }
                    fputcsv($sortie, $ligne, ';');
                }
            });

            fclose($sortie);
        }, 'contrats-'.now()->format('Y-m-d_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
