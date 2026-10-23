<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Models\SourceClient;
use App\Support\CacheReferences;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** Cartes d'indicateurs au-dessus de la liste (comme « Total leads / Open pipeline… »). */
    protected function getHeaderWidgets(): array
    {
        return [\App\Filament\Resources\Clients\Widgets\CartesClients::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('actualiser')
                ->label('Actualiser')
                ->tooltip('Actualiser la liste')
                ->icon(Heroicon::OutlinedArrowPath)
                ->iconButton()
                ->color('gray')
                ->action(fn () => \Filament\Notifications\Notification::make()->title('Liste actualisée')->success()->send()),
            CreateAction::make()
                ->label('Ajouter')
                ->icon(Heroicon::OutlinedPlus),
            Action::make('exporter')
                ->label('Exporter')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->outlined()
                ->action(fn () => $this->exporter()),
        ];
    }

    /** Onglets par source (saisie sur le client ou déduite du canal de vente). */
    public function getTabs(): array
    {
        $icones = ['Terrain' => Heroicon::OutlinedMapPin, 'Marketing' => Heroicon::OutlinedMegaphone, 'Televente' => Heroicon::OutlinedPhone];

        $tabs = ['toutes' => Tab::make('Toutes les sources')->icon(Heroicon::OutlinedSquares2x2)];

        foreach (CacheReferences::tous(SourceClient::class)->sortBy('id_src_client') as $source) {
            $tabs['source-'.$source->id_src_client] = Tab::make($source->lib_src_client)
                ->icon($icones[$source->lib_src_client] ?? Heroicon::OutlinedTag)
                ->modifyQueryUsing(fn (Builder $query) => $query->deSource($source->id_src_client));
        }

        return $tabs;
    }

    /** Export CSV (séparateur « ; », lisible par Excel) des clients affichés avec les filtres en cours. */
    protected function exporter(): StreamedResponse
    {
        $requete = $this->getFilteredSortedTableQuery();

        return response()->streamDownload(function () use ($requete) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel
            $entetes = false;

            $requete->chunk(1000, function ($clients) use ($sortie, &$entetes) {
                foreach ($clients as $client) {
                    $ligne = ClientsTable::ligneExport($client);
                    if (! $entetes) {
                        fputcsv($sortie, array_keys($ligne), ';');
                        $entetes = true;
                    }
                    fputcsv($sortie, $ligne, ';');
                }
            });

            fclose($sortie);
        }, 'clients-greeno-'.now()->format('Y-m-d_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
