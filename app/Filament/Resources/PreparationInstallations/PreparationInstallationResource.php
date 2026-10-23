<?php

namespace App\Filament\Resources\PreparationInstallations;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Filament\Resources\PreparationInstallations\Pages\ListePreparationInstallation;
use App\Formulaires\FormulairePrepInst;
use App\Models\Agent;
use App\Models\CreditScoring;
use App\Models\Localite;
use App\Models\PrepInst;
use App\Support\CacheReferences;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Préparation installation : clients validés au credit scoring, que le CX appelle pour préparer
 * l'installation. Un dossier = le dernier credit scoring « Validé » d'un client par contrat UPYA ;
 * son état vient du dernier appel du CX (table prep_inst).
 */
class PreparationInstallationResource extends Resource
{
    protected static ?string $model = CreditScoring::class;

    protected static ?string $slug = 'preparation-installation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $navigationLabel = 'Préparation installation';

    protected static ?string $modelLabel = 'dossier';

    protected static ?string $pluralModelLabel = 'dossiers à préparer';

    protected static ?int $navigationSort = 4;

    protected static string|\UnitEnum|null $navigationGroup = 'Opérations';

    /** Résultat du dernier appel du CX pour le client du dossier (SQL). */
    public const DERNIER_RESULTAT = '(SELECT p.resul_prep_inst FROM prep_inst p
        WHERE p.id_client = credit_scoring.id_client AND coalesce(p.visibilite, 1) = 1
        ORDER BY p.date_prep DESC, p.heure_prep DESC NULLS LAST LIMIT 1)';

    public static function canAccess(): bool
    {
        return PrepInst::tableDisponible() && \App\Support\Acces::peut('preparation_installation.voir');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! PrepInst::tableDisponible()) {
            return null;
        }

        // Compteur du menu, affiché sur chaque page : recalculé au plus une fois par minute et par utilisateur
        $nombre = Cache::remember('preparation-a-traiter:'.(auth()->id() ?? 'console'), now()->addMinute(),
            fn () => self::filtrerEtat(static::getEloquentQuery(), 'a_traiter')->count());

        return $nombre ? (string) $nombre : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Dossiers à appeler ou à rappeler';
    }

    public static function getEloquentQuery(): Builder
    {
        $valides = fn ($q) => $q->where(CreditScoring::CHAMP_DECISION, CreditScoring::DECISION_VALIDEE)
            ->whereNotNull('id_client')
            ->where(fn ($q) => $q->whereNull('visibilite_cs')->orWhere('visibilite_cs', 1));

        // Dernier scoring validé de chaque client et contrat UPYA
        $derniers = DB::getDriverName() === 'pgsql'
            ? $valides(DB::table('credit_scoring'))
                ->selectRaw("DISTINCT ON (id_client, coalesce(num_contrat_upya, '')) id_cs")
                ->orderByRaw("id_client, coalesce(num_contrat_upya, ''), date_cs DESC NULLS LAST, heure_cs DESC NULLS LAST")
            // Même résultat sans DISTINCT ON (propre à PostgreSQL) : aucun scoring validé plus récent pour ce client et ce contrat
            : $valides(DB::table('credit_scoring as cs'))
                ->select('cs.id_cs')
                ->whereNotExists(fn ($q) => $valides($q->from('credit_scoring as plus_recent'))
                    ->whereColumn('plus_recent.id_client', 'cs.id_client')
                    ->whereRaw("coalesce(plus_recent.num_contrat_upya, '') = coalesce(cs.num_contrat_upya, '')")
                    ->whereRaw("(coalesce(plus_recent.date_cs, '') > coalesce(cs.date_cs, '')
                        or (coalesce(plus_recent.date_cs, '') = coalesce(cs.date_cs, '') and coalesce(plus_recent.heure_cs, '') > coalesce(cs.heure_cs, ''))
                        or (coalesce(plus_recent.date_cs, '') = coalesce(cs.date_cs, '') and coalesce(plus_recent.heure_cs, '') = coalesce(cs.heure_cs, '') and plus_recent.id_cs > cs.id_cs))"));

        return parent::getEloquentQuery()
            ->select('credit_scoring.*')
            ->selectRaw(self::DERNIER_RESULTAT.' AS dernier_resultat')
            ->whereIn('credit_scoring.id_cs', $derniers)
            ->whereHas('client') // applique aussi le filtre d'entité (Greeno / Tevia)
            ->with('client');
    }

    /** Filtre par état du dossier : a_traiter (à appeler + à rappeler) ou une clé de PrepInst::ETATS. */
    public static function filtrerEtat(Builder $query, string $etat): Builder
    {
        $r = self::DERNIER_RESULTAT;
        $rapports = \App\Models\Installation::tableDisponible();
        $installes = \App\Models\Installation::clientsInstalles();

        // Déjà installés : appel CX « déjà installé », ou rapport d'installation « système installé »
        if ($etat === 'installe') {
            return $query->where(fn (Builder $q) => $q
                ->whereRaw("{$r} IN (".implode(', ', PrepInst::ETATS['installe'][2]).')')
                ->when($rapports, fn (Builder $q) => $q->orWhereIn('credit_scoring.id_client', $installes)));
        }

        // Toutes les autres listes attendent une action : un client installé n'y figure plus
        if ($rapports) {
            $query->whereNotIn('credit_scoring.id_client', $installes);
        }

        return match ($etat) {
            'a_traiter' => $query->whereRaw("({$r} IS NULL OR {$r} IN (1, 6, 11))"),
            'a_appeler' => $query->whereRaw("{$r} IS NULL"),
            default => $query->whereRaw("{$r} IN (".implode(', ', PrepInst::ETATS[$etat][2] ?: [0]).')'),
        };
    }

    private static function etat(CreditScoring $record): string
    {
        return PrepInst::etat($record->dernier_resultat !== null ? (int) $record->dernier_resultat : null);
    }

    private static function dateValidation(CreditScoring $record): ?Carbon
    {
        try {
            return $record->q43_cs ? Carbon::parse($record->q43_cs) : $record->date_cs;
        } catch (\Throwable) {
            return $record->date_cs;
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date_cs', 'desc') // derniers validés au crédit en haut
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordUrl(fn (CreditScoring $record) => ClientResource::getUrl('view', ['record' => $record->id_client]))
            ->emptyStateIcon(Heroicon::OutlinedCheckBadge)
            ->emptyStateHeading('Aucun dossier dans cette liste')
            ->emptyStateDescription('Les clients validés au credit scoring arrivent ici pour la préparation de l\'installation.')
            ->columns([
                TextColumn::make('client')
                    ->url(fn (CreditScoring $record) => \App\Support\LiensCrm::client($record->id_client)) // ouvre la fiche client
                    ->label('Client')
                    ->state(fn (CreditScoring $record) => $record->client?->nom_complet)
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('client', fn (Builder $c) => $c
                        ->where('nom_client', 'ilike', "%{$search}%")
                        ->orWhere('prenoms_client', 'ilike', "%{$search}%")
                        ->orWhere('contact1_client', 'ilike', "%{$search}%")
                        ->orWhere('id_client', $search))),
                // Une information par colonne
                TextColumn::make('telephone')
                    ->label('Téléphone')
                    ->state(fn (CreditScoring $record) => ClientsTable::formaterTelephone($record->client?->contact1_client))
                    ->placeholder('—'),
                TextColumn::make('localite')
                    ->label('Localité')
                    ->state(fn (CreditScoring $record) => $record->client?->ref(Localite::class, 'id_localite_commune')?->lib_localite)
                    ->placeholder('—'),
                TextColumn::make('shop')
                    ->label('Shop')
                    ->state(fn (CreditScoring $record) => \App\Support\CacheReferences::libelle(\App\Models\SiteShop::class,
                        $record->client?->ref(Localite::class, 'id_localite_commune')?->id_site_shop))
                    ->placeholder('—'),
                TextColumn::make('num_contrat_upya')
                    ->url(fn (CreditScoring $record) => \App\Support\LiensCrm::contratUpya($record->num_contrat_upya)) // ouvre le contrat
                    ->label('Contrat UPYA')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('pompe')
                    ->label('Pompe')
                    ->state(fn (CreditScoring $record) => $record->libelleReponse('q12_cs'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('date_cs')
                    ->label('Validé au scoring le')
                    ->state(fn (CreditScoring $record) => self::dateValidation($record)?->format('d/m/Y'))
                    ->sortable(),
                TextColumn::make('attente')
                    ->label('Attente')
                    ->state(fn (CreditScoring $record) => ($d = self::dateValidation($record))
                        ? (int) $d->copy()->startOfDay()->diffInDays(now()->startOfDay()).' j'
                        : null)
                    ->placeholder('—'),
                TextColumn::make('etat')
                    ->label('État (dernier appel CX)')
                    ->state(fn (CreditScoring $record) => PrepInst::ETATS[self::etat($record)][0])
                    ->badge()
                    ->color(fn (CreditScoring $record) => PrepInst::ETATS[self::etat($record)][1])
                    ->icon(fn (CreditScoring $record) => match (self::etat($record)) {
                        'pret' => Heroicon::OutlinedCheckBadge,
                        'a_rappeler' => Heroicon::OutlinedPhoneArrowUpRight,
                        'pas_pret', 'resiliation' => Heroicon::OutlinedExclamationTriangle,
                        'installe' => Heroicon::OutlinedHomeModern,
                        default => Heroicon::OutlinedPhone,
                    }),
                TextColumn::make('resultat_appel')
                    ->label('Résultat du dernier appel')
                    ->state(fn (CreditScoring $record) => $record->dernier_resultat !== null
                        ? (PrepInst::RESULTATS[(int) $record->dernier_resultat] ?? null)
                        : 'Aucun appel')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('appelCx')
                    ->authorize(fn () => \App\Support\Acces::peut('preparation_installation.creer'))
                    ->label('Appel CX')
                    ->icon(Heroicon::OutlinedPhone)
                    ->color('primary')
                    ->button()
                    ->modalHeading(fn (CreditScoring $record) => 'Préparation installation · '.$record->client?->nom_complet)
                    ->modalDescription(fn (CreditScoring $record) => self::resumeAppels($record))
                    ->modalSubmitActionLabel('Enregistrer l\'appel')
                    ->modalWidth('4xl')
                    ->slideOver()
                    ->schema(FormulairePrepInst::schema())
                    ->fillForm(fn (CreditScoring $record) => FormulairePrepInst::valeursParDefaut($record->client))
                    ->action(function (CreditScoring $record, array $data) {
                        $appel = FormulairePrepInst::enregistrer($record->client, $data);
                        Notification::make()
                            ->title('Appel enregistré : '.$appel->libelleResultat())
                            ->success()
                            ->send();
                    }),
                ActionGroup::make([
                    Action::make('ouvrirFiche')
                        ->label('Ouvrir la fiche client')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (CreditScoring $record) => ClientResource::getUrl('view', ['record' => $record->id_client])),
                ]),
            ]);
    }

    /** Les 3 derniers appels du CX, en haut du formulaire. */
    private static function resumeAppels(CreditScoring $record): ?HtmlString
    {
        $appels = PrepInst::query()->where('id_client', $record->id_client)
            ->orderByDesc('date_prep')->orderByDesc('heure_prep')->limit(3)->get();

        if ($appels->isEmpty()) {
            return new HtmlString('Premier appel pour ce client.');
        }

        return new HtmlString('Derniers appels : '.$appels->map(fn (PrepInst $a) => e(collect([
            $a->date_prep?->format('d/m/Y'),
            $a->libelleResultat(),
            CacheReferences::libelle(Agent::class, $a->id_agent_traitant),
        ])->filter()->implode(' · ')))->implode(' ; '));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListePreparationInstallation::route('/'),
        ];
    }
}
