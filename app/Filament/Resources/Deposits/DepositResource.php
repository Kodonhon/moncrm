<?php

namespace App\Filament\Resources\Deposits;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Schemas\ClientInfolist;
use App\Filament\Resources\Deposits\Pages\ListeDeposits;
use App\Models\Client;
use App\Models\PaiementContrat;
use App\Support\Acces;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Deposit : paiements des contrats UPYA, lus dans la base des ventes (Railway, vue crm_solaire.paiement_contrat).
 * Lecture seule ; chaque contrat est rattaché au client du CRM quand son n° est connu (scoring, préparation, installation).
 */
class DepositResource extends Resource
{
    protected static ?string $model = PaiementContrat::class;

    protected static ?string $slug = 'deposits';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Deposit';

    protected static ?string $modelLabel = 'deposit';

    protected static ?string $pluralModelLabel = 'deposits';

    protected static ?int $navigationSort = 3;

    protected static string|\UnitEnum|null $navigationGroup = 'Ventes';

    /** Clients du CRM rattachés aux contrats, chargés une fois par requête : [id_client => Client] */
    private static ?array $clients = null;

    public static function canViewAny(): bool
    {
        return filled(config('database.connections.ventes.password')) && Acces::peut('deposits.voir');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function client(PaiementContrat $record): ?Client
    {
        // Passe par le périmètre d'accès : un client hors périmètre n'est pas affiché
        self::$clients ??= Client::query()
            ->whereIn('id_client', array_values(array_unique(PaiementContrat::clientsParContrat())))
            ->get(['id_client', 'nom_client', 'prenoms_client'])
            ->keyBy('id_client')
            ->all();

        return self::$clients[$record->idClient()] ?? null;
    }

    public static function montant($valeur): ?string
    {
        return $valeur === null ? null : number_format((float) $valeur, 0, ',', ' ').' F';
    }

    public static function table(Table $table): Table
    {
        return $table
            // Deposits les plus récents en haut (date du dépôt, puis heure du dernier paiement)
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw('date_deposit DESC NULLS LAST')
                ->orderByRaw('dernier_paiement DESC NULLS LAST'))
            ->paginated([25, 50, 100])
            // Pages « précédente / suivante » sans recompter toute la base des ventes à chaque affichage (~1 s)
            ->paginationMode(\Filament\Tables\Enums\PaginationMode::Simple)
            ->defaultPaginationPageOption(25)
            ->searchPlaceholder('Rechercher un n° de contrat UPYA…')
            // Dépôt partiel : ligne marquée en orange pour la distinguer d'un dépôt complet
            ->recordClasses(fn (PaiementContrat $record) => $record->statut_amt === 'partial_deposit' ? 'gr-ligne-partielle' : null)
            ->recordUrl(fn (PaiementContrat $record) => ($client = self::client($record)) ? ClientResource::getUrl('view', ['record' => $client]) : null)
            ->columns([
                TextColumn::make('num_contrat_upya')
                    ->url(fn (PaiementContrat $record) => \App\Support\LiensCrm::contratUpya($record->num_contrat_upya)) // ouvre le contrat du CRM, s'il existe
                    ->label('Contrat UPYA')
                    ->weight('medium')
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('num_contrat_upya', 'like', '%'.preg_replace('/\D/', '', $search).'%')),
                TextColumn::make('client_crm')
                    ->url(fn (PaiementContrat $record) => \App\Support\LiensCrm::client(self::client($record)?->id_client)) // ouvre la fiche client
                    ->label('Client CRM')
                    ->state(fn (PaiementContrat $record) => ($client = self::client($record))
                        ? new HtmlString('<span class="gr-liste-client">'.ClientInfolist::avatar($client, 'gr-ent-avatar gr-liste-avatar')
                            .'<span class="gr-liste-nom">'.e($client->nom_complet).'</span></span>')
                        : null)
                    ->html()
                    ->placeholder('Non rattaché'),
                TextColumn::make('entite')
                    ->label('Entité')
                    ->formatStateUsing(fn (?string $state) => PaiementContrat::ENTITES[$state] ?? $state)
                    ->badge()
                    ->color(fn (?string $state) => $state === 'upya_greeno' ? 'success' : 'info')
                    ->placeholder('—'),
                TextColumn::make('statut_amt')
                    ->label('Statut')
                    ->formatStateUsing(fn (?string $state) => PaiementContrat::STATUTS[$state] ?? $state)
                    ->badge()
                    ->color(fn (?string $state) => $state === 'full_deposit' ? 'success' : 'warning'),
                TextColumn::make('mt_deposit')
                    ->label('Dépôt')
                    ->formatStateUsing(fn ($state) => self::montant($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('total_paye')
                    ->label('Total payé')
                    ->formatStateUsing(fn ($state) => self::montant($state))
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('date_deposit')
                    ->label('Date du dépôt')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('premier_paiement')
                    ->label('Premier paiement')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('dernier_paiement')
                    ->label('Dernier paiement')
                    ->since()
                    ->dateTooltip('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('entite')
                    ->label('Entité')
                    ->options(PaiementContrat::ENTITES),
                Filter::make('periode')
                    ->label('Période du dépôt')
                    ->schema([
                        DatePicker::make('du')->label('Du')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('au')->label('Au')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['du'] ?? null, fn (Builder $q, $du) => $q->whereDate('date_deposit', '>=', $du))
                        ->when($data['au'] ?? null, fn (Builder $q, $au) => $q->whereDate('date_deposit', '<=', $au))),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading('Aucun deposit');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListeDeposits::route('/'),
        ];
    }
}
