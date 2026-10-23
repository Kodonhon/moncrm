<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Clients\Actions\KycActions;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Formulaires\ConstructeurFormulaire;
use App\Models\Agent;
use App\Models\Client;
use App\Models\CreditScoring;
use App\Models\Kyc;
use App\Models\Localite;
use App\Models\PaiementContrat;
use App\Models\SiteShop;
use App\Models\Ville;
use App\Filament\Resources\Clients\Schemas\ClientInfolist;
use App\Support\Acces;
use App\Support\CacheReferences;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

/**
 * File de l'agent crédit, présentée comme la préparation installation : un onglet par groupe de dossiers
 * (KYC à valider, scoring à faire, renvoyés au terrain, en attente du deposit, validés), et les actions
 * du crédit directement sur chaque ligne (valider le KYC, faire le credit scoring).
 */
class ValidationCredit extends Page implements HasTable
{
    use HasTabs;
    use InteractsWithTable;

    protected static ?string $slug = 'validation-credit';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Credit scoring';

    protected static ?string $title = 'Credit scoring';

    protected static ?int $navigationSort = 3;

    protected static string|\UnitEnum|null $navigationGroup = 'Crédit';

    /** Groupes de la file : clé de Client::scopeFileCredit => [libellé, icône, couleur du compteur] */
    private const GROUPES = [
        'a_traiter' => ['À traiter', Heroicon::OutlinedInboxStack, 'warning'],
        'injoignable' => ['Injoignable', Heroicon::OutlinedPhoneXMark, 'gray'],
        'mise_a_jour' => ['En attente de mise à jour', Heroicon::OutlinedArrowPath, 'info'],
        'invalide' => ['Invalidé', Heroicon::OutlinedXCircle, 'danger'],
        'valide' => ['Validé', Heroicon::OutlinedCheckBadge, 'success'],
    ];

    /** Les écrans du crédit apparaissent une fois le script database/sql/credit_scoring.sql exécuté. */
    public static function canAccess(): bool
    {
        return CreditScoring::tableDisponible() && Acces::peut('credit_scoring.voir');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! CreditScoring::tableDisponible()) {
            return null;
        }

        $nombre = self::compter('a_traiter');

        return $nombre ? (string) $nombre : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Dossiers à traiter : KYC à valider ou scoring à faire';
    }

    private static function compter(string $groupe): int
    {
        return Cache::remember("file-credit:{$groupe}:".(auth()->id() ?? 'console'), now()->addMinute(),
            fn () => Client::query()->fileCredit($groupe)->count());
    }

    /** Onglets dessinés comme ceux des listes (pastilles, l'active en violet plein). */
    public function getPageClasses(): array
    {
        return ['gr-page-onglets'];
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    public function getSubheading(): ?string
    {
        return 'Dossiers transmis par le terrain (KYC + dimensionnement + deposit) : validez le KYC, puis faites le credit scoring.';
    }

    public function getTabs(): array
    {
        return collect(self::GROUPES)->map(fn (array $g, string $cle) => Tab::make($g[0])
            ->icon($g[1])
            ->badge(fn () => self::compter($cle))
            ->badgeColor($g[2])
            ->modifyQueryUsing(fn (Builder $query) => $query->fileCredit($cle))
        )->all();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Client::query()
                ->with(['kyc', 'creditScoring'])
                ->withCount(['evalSites', 'creditScorings']))
            ->modifyQueryUsing(fn (Builder $query) => $this->modifyQueryWithActiveTab($query))
            ->defaultSort('date_client', 'desc') // nouveaux dossiers en haut
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchPlaceholder('Rechercher : nom, téléphone, n° client…')
            ->recordUrl(fn (Client $record) => ClientResource::getUrl('view', ['record' => $record]))
            // Une information par colonne ; toutes s'affichent ou se masquent avec le bouton « Colonnes »
            ->columns(self::colonnes())
            ->recordActions([
                KycActions::validationCredit()->button()->size('sm'),
                $this->actionScoring(),
                ActionGroup::make([
                    Action::make('ouvrirFiche')
                        ->label('Ouvrir la fiche client')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (Client $record) => ClientResource::getUrl('view', ['record' => $record])),
                ]),
            ])
            ->emptyStateHeading('Aucun dossier dans ce groupe')
            ->emptyStateDescription('Les dossiers transmis par le terrain arrivent ici.')
            ->emptyStateIcon(Heroicon::OutlinedScale);
    }

    /** Colonnes de la file : une information chacune ; les secondaires sont masquées au départ. */
    private static function colonnes(): array
    {
        $localite = fn (Client $record) => $record->ref(Localite::class, 'id_localite_commune');
        $decision = fn (Client $record) => (string) $record->creditScoring?->{CreditScoring::CHAMP_DECISION};

        return [
            TextColumn::make('id_client')
                ->label('N° client')
                ->fontFamily('mono')
                ->size('sm')
                ->copyable()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('nom_client')
                ->url(fn (Client $record) => \App\Support\LiensCrm::client($record->id_client)) // ouvre la fiche client
                ->label('Client')
                ->formatStateUsing(fn (Client $record) => new HtmlString('<span class="gr-liste-client">'
                    .ClientInfolist::avatar($record, 'gr-ent-avatar gr-liste-avatar')
                    .'<span class="gr-liste-nom">'.e($record->nom_complet).'</span></span>'))
                ->html()
                ->weight('medium')
                ->sortable()
                ->searchable(['nom_client', 'prenoms_client']),
            TextColumn::make('contact1_client')
                ->label('Téléphone')
                ->formatStateUsing(fn (?string $state) => ClientsTable::formaterTelephone($state))
                ->copyable()
                ->copyableState(fn (Client $record) => preg_replace('/\D/', '', (string) $record->contact1_client))
                ->searchable(['contact1_client', 'contact2_client'])
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('localite')
                ->label('Localité')
                ->state(fn (Client $record) => $localite($record)?->lib_localite)
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('ville')
                ->label('Ville')
                ->state(fn (Client $record) => CacheReferences::libelle(Ville::class, $localite($record)?->id_ville))
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('shop')
                ->label('Shop')
                ->state(fn (Client $record) => CacheReferences::libelle(SiteShop::class, $localite($record)?->id_site_shop))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('agent_terrain')
                ->label('Agent terrain')
                ->state(fn (Client $record) => CacheReferences::libelle(Agent::class, $record->kyc?->id_agent ?? $record->id_agent))
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('date_kyc')
                ->label('Date du KYC')
                ->state(fn (Client $record) => $record->kyc?->date_kyc)
                ->date('d/m/Y')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('attente')
                ->label('Attente')
                ->state(fn (Client $record) => $record->kyc?->date_kyc
                    ? (int) $record->kyc->date_kyc->copy()->startOfDay()->diffInDays(now()->startOfDay()).' j'
                    : null)
                ->alignEnd()
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('etat_kyc')
                ->label('KYC')
                ->state(fn (Client $record) => $record->kyc?->etatValidation()['libelle'])
                ->badge()
                ->color(fn (Client $record) => $record->kyc?->etatValidation()['couleur'] ?? 'gray')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('motif_kyc')
                ->label('Motif KYC à corriger')
                ->state(fn (Client $record) => (int) $record->kyc?->statut_validation_kyc === Kyc::VALIDATION_A_CORRIGER
                    ? $record->kyc->motif_validation_kyc
                    : null)
                ->limit(40)
                ->tooltip(fn (Client $record) => $record->kyc?->motif_validation_kyc)
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('eval_sites_count')
                ->label('Dimensionnements')
                ->alignCenter()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('contrat_upya')
                ->url(fn (Client $record) => \App\Support\LiensCrm::contratUpya($record->creditScoring?->num_contrat_upya ?: $record->num_contrat_upya_actuel)) // ouvre le contrat
                ->label('Contrat UPYA')
                ->state(fn (Client $record) => $record->creditScoring?->num_contrat_upya ?: $record->num_contrat_upya_actuel)
                ->fontFamily('mono')
                ->size('sm')
                ->copyable()
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('deposit')
                ->label('Deposit')
                ->state(function (Client $record) {
                    $numero = $record->creditScoring?->num_contrat_upya ?: $record->num_contrat_upya_actuel;

                    return $numero && PaiementContrat::verificationActive()
                        ? (PaiementContrat::STATUTS[PaiementContrat::depositDe($numero)['statut'] ?? ''] ?? 'Aucun deposit')
                        : null;
                })
                ->badge()
                ->color(fn (?string $state) => match ($state) {
                    'Dépôt complet' => 'success',
                    'Dépôt partiel' => 'warning',
                    default => 'gray',
                })
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('decision')
                ->label('Décision')
                ->state(fn (Client $record) => $record->creditScoring?->libelleDecision())
                ->badge()
                ->color(fn (Client $record) => match (true) {
                    $decision($record) === CreditScoring::DECISION_VALIDEE => 'success',
                    in_array($decision($record), CreditScoring::DECISIONS_INVALIDE, true) => 'danger',
                    in_array($decision($record), CreditScoring::DECISIONS_INJOIGNABLE, true) => 'gray',
                    in_array($decision($record), CreditScoring::DECISIONS_MISE_A_JOUR, true) => 'info',
                    default => 'warning',
                })
                ->placeholder('Scoring à faire')
                ->toggleable(),
            TextColumn::make('date_scoring')
                ->label('Date du scoring')
                ->state(fn (Client $record) => $record->creditScoring?->date_cs)
                ->date('d/m/Y')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('appel')
                ->label('Appel')
                ->state(fn (Client $record) => $record->creditScoring?->libelleReponse(CreditScoring::CHAMP_APPEL))
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('agent_credit')
                ->label('Agent crédit')
                ->state(fn (Client $record) => CacheReferences::libelle(Agent::class, $record->creditScoring?->id_agent))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('commentaire')
                ->label('Commentaire')
                ->state(fn (Client $record) => $record->creditScoring?->{CreditScoring::CHAMP_COMMENTAIRE})
                ->limit(40)
                ->tooltip(fn (Client $record) => $record->creditScoring?->{CreditScoring::CHAMP_COMMENTAIRE})
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('credit_scorings_count')
                ->label('Scorings')
                ->alignCenter()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('date_client')
                ->label('Enregistré le')
                ->date('d/m/Y')
                ->sortable()
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /** « Credit scoring » directement depuis la file (même formulaire que sur la fiche client). */
    private function actionScoring(): Action
    {
        return Action::make('faireScoring')
            ->authorize(fn () => Acces::peut('credit_scoring.creer'))
            ->label('Credit scoring')
            ->icon(Heroicon::OutlinedScale)
            ->color('primary')
            ->button()
            ->size('sm')
            ->visible(fn (Client $record) => (int) $record->kyc?->statut_validation_kyc === Kyc::VALIDATION_VALIDE
                && ! $record->creditScoring?->estValide())
            ->disabled(fn (Client $record) => $record->raisonScoringImpossible() !== null)
            ->tooltip(fn (Client $record) => $record->raisonScoringImpossible())
            ->modalHeading(fn (Client $record) => 'Credit scoring · '.$record->nom_complet)
            ->modalDescription(fn (Client $record) => $record->kyc
                ? 'KYC n° '.$record->kyc->id_kyc.' · '.($record->kyc->num_piece_id ?: 'pièce non renseignée').' · '.$record->kyc->etatValidation()['libelle']
                : 'Aucun KYC')
            ->modalSubmitActionLabel('Enregistrer le scoring')
            ->modalWidth('5xl')
            ->slideOver()
            ->schema(fn (Client $record) => (new ConstructeurFormulaire('credit_scoring', $record))->sections())
            ->fillForm(fn (Client $record) => array_merge(
                (new ConstructeurFormulaire('credit_scoring'))->valeursParDefaut($record),
                array_filter(['id_agent' => $record->creditScoring?->id_agent], fn ($valeur) => filled($valeur)),
            ))
            ->action(function (Client $record, array $data) {
                $data = (new ConstructeurFormulaire('credit_scoring', $record))->appliquerCalculs($data);

                $scoring = $record->creditScorings()->create($data + [
                    'id_kyc' => $record->kyc?->id_kyc,
                    'id_prospect' => $record->id_client,
                ]);
                $record->unsetRelation('creditScoring');
                collect(array_keys(self::GROUPES))->each(fn ($g) => Cache::forget("file-credit:{$g}:".auth()->id()));

                Notification::make()
                    ->title('Credit scoring enregistré')
                    ->body($scoring->estValide() ? 'Dossier validé par le crédit : il passe en préparation installation.' : null)
                    ->success()
                    ->send();
            });
    }
}
