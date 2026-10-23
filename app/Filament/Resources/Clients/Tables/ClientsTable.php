<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Agent;
use App\Models\CanalVente;
use App\Models\Client;
use App\Models\Entite;
use App\Models\EvalSite;
use App\Models\Genre;
use App\Models\Localite;
use App\Models\SiteShop;
use App\Models\SourceClient;
use App\Models\TypePiece;
use App\Models\Ville;
use App\Support\CacheReferences;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Liste des clients, façon « liste de comptes » : filtres toujours visibles, tableau plein,
 * toutes les informations affichées en clair (numéros complets).
 */
class ClientsTable
{
    /** Colonnes masquées au départ (bouton « Colonnes » pour les réafficher). */
    public const COLONNES_MASQUEES = ['contact2_client', 'type_piece', 'indication_site_client', 'entite_id_client', 'genre_client', 'source', 'shop'];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date_client', 'desc')
            ->recordUrl(fn (Client $record) => ClientResource::getUrl('view', ['record' => $record]))
            // Libellés lus dans le cache des références ; KYC (n° de pièce) chargé en une requête ;
            // dimensionnement en sous-requêtes dans la requête principale.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['kyc:id_kyc,id_client,num_piece_id,type_piece_id,date_expir_doc,visibilite_kyc,date_kyc'])
                ->withExists(['evalSites as eval_faite'])
                ->withExists(['evalSites as eval_validee' => fn (Builder $eval) => $eval->where('statut_eval_site', EvalSite::STATUT_VALIDEE)]))
            ->searchPlaceholder('Rechercher : nom, téléphone, n° client, n° de pièce…')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            // « Actualiser » est en haut de la page (ListClients), pour ne pas ajouter une ligne vide au tableau
            // Toutes les colonnes sont affichées ; chacune peut être masquée avec le bouton « Colonnes » (sauf Client)
            // Six colonnes secondaires masquées au départ pour que le tableau tienne à l'écran (réaffichables)
            ->columns(array_map(
                fn (TextColumn $colonne) => $colonne->getName() === 'nom_client' ? $colonne : $colonne->toggleable(
                    isToggledHiddenByDefault: in_array($colonne->getName(), self::COLONNES_MASQUEES, true),
                ),
                self::colonnes(),
            ))
            ->columnManagerTriggerAction(fn (Action $action) => $action->label('Colonnes')->button()->outlined()->color('gray'))
            // Filtres dans le menu de l'entonnoir (à côté de la recherche), pour garder la liste dégagée
            ->filters(self::filtres(), layout: FiltersLayout::Dropdown)
            ->filtersFormColumns(2)
            ->filtersFormWidth(Width::FourExtraLarge)
            ->deferFilters(false)
            ->recordActions([])
            ->toolbarActions([
                BulkActionGroup::make([
                    \App\Filament\Messages\EnvoiSms::bulk(),
                    BulkAction::make('desactiver')
                        ->label('Désactiver')
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Les clients désactivés sont masqués de la liste (filtre Affichage > Clients désactivés). Rien n\'est supprimé.')
                        ->action(fn (Collection $records) => self::changerVisibilite($records, false)),
                    BulkAction::make('reactiver')
                        ->label('Réactiver')
                        ->icon(Heroicon::OutlinedEye)
                        ->action(fn (Collection $records) => self::changerVisibilite($records, true)),
                ]),
            ])
            ->emptyStateHeading('Aucun client')
            ->emptyStateDescription('Modifiez les filtres ou cliquez sur « Ajouter » pour enregistrer un client.')
            ->emptyStateIcon('heroicon-o-user-plus');
    }

    private static function colonnes(): array
    {
        $ref = fn (string $modele, string $colonne) => fn (Client $record) => CacheReferences::libelle($modele, $record->{$colonne});

        return [
            TextColumn::make('id_client')
                ->label('N° client')
                ->searchable()
                ->sortable()
                ->icon(Heroicon::OutlinedEye)
                ->iconColor('primary')
                ->fontFamily('mono')
                ->size('sm'),
            TextColumn::make('contact1_client')
                ->label('Téléphone')
                ->searchable(['contact1_client', 'contact2_client'])
                ->sortable()
                ->formatStateUsing(fn (?string $state) => self::formaterTelephone($state))
                ->copyable()
                ->copyableState(fn (Client $record) => preg_replace('/\D/', '', (string) $record->contact1_client))
                ->copyMessage('Numéro copié')
                ->extraAttributes(['class' => 'gr-lien-pointille']),
            TextColumn::make('nom_client')
                ->label('Client')
                ->sortable()
                ->searchable(['nom_client', 'prenoms_client'])
                // Initiales en pastille devant le nom, comme sur la fiche
                ->formatStateUsing(fn (Client $record) => new \Illuminate\Support\HtmlString('<span class="gr-liste-client">'
                    .\App\Filament\Resources\Clients\Schemas\ClientInfolist::avatar($record, 'gr-ent-avatar gr-liste-avatar')
                    .'<span class="gr-liste-nom">'.e($record->nom_complet).'</span></span>'))
                ->html()
                ->weight('medium'),
            TextColumn::make('contact2_client')
                ->label('Téléphone 2')
                ->formatStateUsing(fn (?string $state) => self::formaterTelephone($state))
                ->placeholder('—'),
            TextColumn::make('piece')
                ->label('N° de pièce')
                ->state(fn (Client $record) => $record->kyc?->num_piece_id)
                ->copyable()
                ->copyMessage('N° de pièce copié')
                ->searchable(query: fn (Builder $query, string $search) => $query->orWhereIn(
                    'id_client',
                    fn ($kyc) => $kyc->select('id_client')->from('kyc')->where('num_piece_id', 'like', '%'.$search.'%'),
                ))
                ->placeholder('—'),
            TextColumn::make('type_piece')
                ->label('Type de pièce')
                ->state(fn (Client $record) => CacheReferences::libelle(TypePiece::class, $record->kyc?->type_piece_id ?? $record->id_type_piece_id))
                ->placeholder('—'),
            TextColumn::make('statut_kyc')
                ->label('KYC')
                ->state(fn (Client $record) => match (true) {
                    ! $record->kyc => 'À faire',
                    $record->kyc->etatPiece()['etat'] === 'expiree' => 'Pièce expirée',
                    default => 'Fait',
                })
                ->badge()
                ->color(fn (string $state) => match ($state) {
                    'Fait' => 'success',
                    'Pièce expirée' => 'danger',
                    default => 'warning',
                }),
            TextColumn::make('statut_eval')
                ->label('Dimensionnement')
                ->state(fn (Client $record) => match (true) {
                    (bool) $record->eval_validee => 'Validé',
                    (bool) $record->eval_faite => 'À valider',
                    default => 'À faire',
                })
                ->badge()
                ->color(fn (string $state) => match ($state) {
                    'Validé' => 'success',
                    'À valider' => 'info',
                    default => 'gray',
                }),
            TextColumn::make('id_localite_commune')
                ->label('Localité')
                ->sortable()
                ->formatStateUsing($ref(Localite::class, 'id_localite_commune'))
                ->placeholder('—'),
            TextColumn::make('ville')
                ->label('Ville')
                ->state(fn (Client $record) => CacheReferences::libelle(Ville::class, $record->ref(Localite::class, 'id_localite_commune')?->id_ville))
                ->placeholder('—'),
            TextColumn::make('shop')
                ->label('Site / shop')
                ->state(fn (Client $record) => ($shop = $record->ref(Localite::class, 'id_localite_commune')?->id_site_shop)
                    ? CacheReferences::libelle(SiteShop::class, $shop)
                    : null)
                ->placeholder('—'),
            TextColumn::make('indication_site_client')
                ->label('Indication du site')
                ->limit(28)
                ->tooltip(fn (Client $record) => $record->indication_site_client)
                ->searchable()
                ->placeholder('—'),
            TextColumn::make('id_canal_vente')
                ->label('Canal')
                ->sortable()
                ->formatStateUsing($ref(CanalVente::class, 'id_canal_vente'))
                ->placeholder('—'),
            TextColumn::make('source')
                ->label('Source')
                ->state(fn (Client $record) => $record->source_effective?->lib_src_client)
                ->placeholder('—'),
            TextColumn::make('entite_id_client')
                ->label('Entité')
                ->sortable()
                ->formatStateUsing($ref(Entite::class, 'entite_id_client'))
                ->placeholder('—'),
            TextColumn::make('id_agent')
                ->label('Agent')
                ->sortable()
                ->formatStateUsing($ref(Agent::class, 'id_agent'))
                ->placeholder('—'),
            TextColumn::make('genre_client')
                ->label('Genre')
                ->formatStateUsing($ref(Genre::class, 'genre_client'))
                ->placeholder('—'),
            TextColumn::make('date_client')
                ->label('Enregistré le')
                ->date('d M Y')
                ->sortable(),
        ];
    }

    private static function filtres(): array
    {
        return [
            SelectFilter::make('shop')
                ->label('Site / shop')
                ->placeholder('Tous les sites')
                ->options(fn () => array_filter(SiteShop::tousLesLibelles(), fn ($id) => $id > 0, ARRAY_FILTER_USE_KEY))
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, string $shop) => $query->duShop((int) $shop))),
            SelectFilter::make('id_canal_vente')
                ->label('Canal de vente')
                ->placeholder('Tous les canaux')
                ->options(fn () => CanalVente::tousLesLibelles())
                ->searchable(),
            SelectFilter::make('source')
                ->label('Source')
                ->placeholder('Toutes les sources')
                ->options(fn () => SourceClient::tousLesLibelles())
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, string $source) => $query->deSource((int) $source))),
            SelectFilter::make('kyc')
                ->label('KYC')
                ->placeholder('Tous')
                ->options([
                    'fait' => 'KYC fait',
                    'a_faire' => 'KYC à faire',
                    'expiree' => 'Pièce expirée',
                    'bientot' => 'Pièce expirant dans 30 jours',
                ])
                ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'fait' => $query->avecKyc(),
                    'a_faire' => $query->avecKyc(false),
                    'expiree', 'bientot' => $query->pieceKyc($data['value']),
                    default => $query,
                }),
            SelectFilter::make('eval_site')
                ->label('Dimensionnement')
                ->placeholder('Toutes')
                ->options([
                    'faite' => 'Dimensionnement fait',
                    'a_faire' => 'Dimensionnement à faire',
                    'a_valider' => 'Dimensionnement à valider',
                    'validee' => 'Dimensionnement validé',
                ])
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->evaluationSite($data['value']) : $query),
            SelectFilter::make('id_agent')
                ->label('Agent')
                ->placeholder('Tous les agents')
                ->options(fn () => Agent::tousLesLibelles())
                ->searchable(),
            SelectFilter::make('entite_id_client')
                ->label('Entité')
                ->placeholder('Toutes les entités')
                ->options(fn () => Entite::tousLesLibelles()),
            SelectFilter::make('ville')
                ->label('Ville')
                ->placeholder('Toutes les villes')
                ->options(fn () => array_filter(Ville::tousLesLibelles(), fn ($id) => $id > 0, ARRAY_FILTER_USE_KEY))
                ->searchable()
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, string $ville) => $query->deLaVille((int) $ville))),
            SelectFilter::make('id_localite_commune')
                ->label('Localité')
                ->placeholder('Toutes les localités')
                ->options(fn () => Localite::tousLesLibelles())
                ->searchable(),
            SelectFilter::make('periode_rapide')
                ->label('Période')
                ->placeholder('Toutes les dates')
                ->options([
                    'aujourdhui' => 'Aujourd\'hui',
                    'semaine' => 'Cette semaine',
                    'mois' => 'Ce mois-ci',
                    '30' => '30 derniers jours',
                    '90' => '3 derniers mois',
                ])
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, string $periode) => $query->where('date_client', '>=', match ($periode) {
                    'aujourdhui' => now()->toDateString(),
                    'semaine' => now()->startOfWeek()->toDateString(),
                    'mois' => now()->startOfMonth()->toDateString(),
                    default => now()->subDays((int) $periode)->toDateString(),
                }))),
            Filter::make('periode')
                ->label('Date d\'enregistrement')
                ->schema([
                    Grid::make(2)->schema([
                        DatePicker::make('du')->label('Enregistré du')->native(false)->displayFormat('d/m/Y')->placeholder('Date de début'),
                        DatePicker::make('au')->label('au')->native(false)->displayFormat('d/m/Y')->placeholder('Date de fin'),
                    ]),
                ])
                ->columnSpanFull()
                ->query(fn (Builder $query, array $data) => $query
                    ->when($data['du'] ?? null, fn (Builder $query, string $du) => $query->where('date_client', '>=', $du))
                    ->when($data['au'] ?? null, fn (Builder $query, string $au) => $query->where('date_client', '<=', $au)))
                ->indicateUsing(fn (array $data) => array_filter([
                    ($data['du'] ?? null) ? 'Du '.\Illuminate\Support\Carbon::parse($data['du'])->format('d/m/Y') : null,
                    ($data['au'] ?? null) ? 'Au '.\Illuminate\Support\Carbon::parse($data['au'])->format('d/m/Y') : null,
                ])),
            SelectFilter::make('genre_client')
                ->label('Genre')
                ->placeholder('Tous')
                ->options(fn () => Genre::tousLesLibelles()),
            SelectFilter::make('affichage')
                ->label('Affichage')
                ->options([
                    'actifs' => 'Clients actifs',
                    'desactives' => 'Clients désactivés',
                    'tous' => 'Tous les clients',
                ])
                ->default('actifs')
                ->selectablePlaceholder(false)
                // Pas de rappel « Filtres actifs » pour le réglage par défaut
                ->indicateUsing(fn (array $data) => match ($data['value'] ?? 'actifs') {
                    'desactives' => ['Clients désactivés'],
                    'tous' => ['Tous les clients (actifs et désactivés)'],
                    default => [],
                })
                ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'actifs') {
                    'desactives' => $query->where('visibilite_client', 0),
                    'tous' => $query,
                    // visibilité vide = actif (cas des clients d'origine)
                    default => $query->where(fn (Builder $query) => $query->whereNull('visibilite_client')->orWhere('visibilite_client', '<>', 0)),
                }),
        ];
    }

    /** 2250707070707 → +225 07 07 07 07 07 ; 10 chiffres → +225 … ; autre format : tel quel. */
    public static function formaterTelephone(?string $numero): ?string
    {
        if (! $numero) {
            return null;
        }

        $chiffres = preg_replace('/\D/', '', $numero);

        return match (true) {
            (bool) preg_match('/^225(\d{10})$/', $chiffres, $m) => '+225 '.trim(chunk_split($m[1], 2, ' ')),
            strlen($chiffres) === 10 => '+225 '.trim(chunk_split($chiffres, 2, ' ')),
            default => $numero,
        };
    }

    private static function changerVisibilite(Collection $clients, bool $actif): void
    {
        Client::whereKey($clients->modelKeys())->update(['visibilite_client' => $actif ? 1 : 0]);

        Notification::make()
            ->title($clients->count().' client(s) '.($actif ? 'réactivé(s)' : 'désactivé(s)'))
            ->success()
            ->send();
    }

    /** Ligne d'export CSV : toutes les informations en clair. */
    public static function ligneExport(Client $client): array
    {
        $localite = $client->ref(Localite::class, 'id_localite_commune');

        return [
            'N° client' => $client->id_client,
            'Nom' => $client->nom_client,
            'Prénoms' => $client->prenoms_client,
            'Téléphone' => $client->contact1_client,
            'Téléphone 2' => $client->contact2_client,
            'E-mail' => $client->email_client,
            'Genre' => CacheReferences::libelle(Genre::class, $client->genre_client),
            'Type de pièce' => CacheReferences::libelle(TypePiece::class, $client->kyc?->type_piece_id ?? $client->id_type_piece_id),
            'N° de pièce' => $client->kyc?->num_piece_id,
            'KYC' => $client->kyc ? 'Fait' : 'À faire',
            'Dimensionnement' => $client->eval_validee ? 'Validé' : ($client->eval_faite ? 'À valider' : 'À faire'),
            'Localité' => $localite?->lib_localite,
            'Ville' => CacheReferences::libelle(Ville::class, $localite?->id_ville),
            'Site / shop' => $localite?->id_site_shop ? CacheReferences::libelle(SiteShop::class, $localite->id_site_shop) : null,
            'Indication du site' => $client->indication_site_client,
            'Canal' => CacheReferences::libelle(CanalVente::class, $client->id_canal_vente),
            'Source' => $client->source_effective?->lib_src_client,
            'Entité' => CacheReferences::libelle(Entite::class, $client->entite_id_client),
            'Agent' => CacheReferences::libelle(Agent::class, $client->id_agent),
            'Enregistré le' => $client->date_client?->format('d/m/Y'),
        ];
    }
}
