<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Filament\Resources\PreparationInstallations\PreparationInstallationResource as Preparation;
use App\Models\Agent;
use App\Models\AssignationKit;
use App\Models\CreditScoring;
use App\Models\Localite;
use App\Models\PrepInst;
use App\Models\SiteShop;
use App\Models\Ville;
use App\Support\CacheReferences;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * File des shop managers : clients déclarés « prêts pour l'installation » par le CX (dernier appel).
 * Cette page (« Assignation des kits ») : le shop manager assigne le kit (n° de série du contrôleur, de la pompe, des panneaux).
 * Page PretsInstallation (« Prêts pour l'installation », entrée séparée du menu) : mêmes colonnes et actions,
 * clients dont le kit est assigné, où le technicien les retrouve.
 * Un client installé (rapport « système installé ») sort des deux listes. Filtrable par shop et par ville.
 */
class AssignationKits extends Page implements HasTable
{
    use HasTabs;
    use InteractsWithTable;

    /** Liste affichée par cette page : « a_assigner » ici, « pret_installation » pour PretsInstallation. */
    protected static string $liste = 'a_assigner';

    /** Assignations en cours, chargées une fois par requête : [id_client => AssignationKit] */
    private static ?array $assignations = null;

    protected static ?string $slug = 'assignation-kits';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Assignation des kits';

    protected static ?string $title = 'En attente d\'assignation de kit';

    protected static ?int $navigationSort = 5;

    protected static string|\UnitEnum|null $navigationGroup = 'Opérations';

    /** Appel du CX le plus récent : date et agent (pour « prêt depuis » et « validé par »). */
    private const DERNIER_APPEL = '(SELECT p.%s FROM prep_inst p
        WHERE p.id_client = credit_scoring.id_client AND coalesce(p.visibilite, 1) = 1
        ORDER BY p.date_prep DESC, p.heure_prep DESC LIMIT 1)';

    public static function canAccess(): bool
    {
        return PrepInst::tableDisponible() && \App\Support\Acces::peut('assignation_kits.voir');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! PrepInst::tableDisponible()) {
            return null;
        }

        // Clients de cette liste, comptés dans le périmètre de l'utilisateur : une clé de cache par utilisateur
        $nombre = self::compter(static::$liste);

        return $nombre ? (string) $nombre : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Clients prêts pour l\'installation, kit à assigner';
    }

    public function getSubheading(): ?string
    {
        return 'Clients déclarés « prêts pour l\'installation » par le CX : assignez-leur un kit (numéros de série), ils passent alors dans « Prêts pour l\'installation » pour le technicien.';
    }

    /** Sous-requête : clients ayant un kit assigné (en cours). */
    private static function assignes(): \Closure
    {
        return fn (\Illuminate\Database\Query\Builder $q) => $q->select('id_client')->from('assignation_kit')->where('statut', 'assigne');
    }

    /** Clients de la file, selon la liste : à assigner, ou kit assigné (prêts pour l'installation). */
    public static function filtrer(Builder $query, string $onglet): Builder
    {
        if (! AssignationKit::tableDisponible()) {
            return $onglet === 'a_assigner' ? $query : $query->whereRaw('1 = 0');
        }

        $colonne = $query->getModel()->qualifyColumn('id_client');

        return $onglet === 'a_assigner'
            ? $query->whereNotIn($colonne, self::assignes())
            : $query->whereIn($colonne, self::assignes()); // assignés : non installés, ou installés
    }

    private static function compter(string $onglet): int
    {
        return Cache::remember("kits-{$onglet}:".(auth()->id() ?? 'console'), now()->addMinute(),
            fn () => self::requete($onglet)->count());
    }

    /** Page à onglets (Assignation des kits) ou liste fixe (Prêts pour l'installation). */
    protected static bool $avecOnglets = true;

    /** Liste affichée : l'onglet choisi, ou la liste fixe de la page. */
    public function liste(): string
    {
        return static::$avecOnglets ? ($this->activeTab ?: 'a_assigner') : static::$liste;
    }

    /** Colonnes du kit (numéros de série…) : seulement pour les clients qui ont un kit assigné. */
    public function avecKit(): bool
    {
        return in_array($this->liste(), ['pret_installation', 'installe'], true);
    }

    public function getPageClasses(): array
    {
        return ['gr-page-onglets'];
    }

    /** À chaque requête Livewire : assignations et installations relues (pas de mémoire d'une requête à l'autre). */
    public function booted(): void
    {
        self::$assignations = null;
        self::$installations = null;
    }

    public function mount(): void
    {
        if (static::$avecOnglets) {
            $this->loadDefaultActiveTab();
        }
    }

    public function getTabs(): array
    {
        if (! static::$avecOnglets || ! AssignationKit::tableDisponible()) {
            return [];
        }

        // Les onglets ne filtrent pas eux-mêmes : la requête du tableau dépend de l'onglet (voir liste())
        return [
            'a_assigner' => Tab::make('Non assignés')->icon(Heroicon::OutlinedCube)
                ->badge(fn () => self::compter('a_assigner'))->badgeColor('warning'),
            'pret_installation' => Tab::make('Assignés · non installés')->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->badge(fn () => self::compter('pret_installation'))->badgeColor('info'),
            'installe' => Tab::make('Assignés · installés')->icon(Heroicon::OutlinedHomeModern)
                ->badge(fn () => self::compter('installe'))->badgeColor('success'),
        ];
    }

    /** Date d'installation des clients installés (onglet « Assignés · installés »), chargée une fois. */
    private static ?array $installations = null;

    public static function dateInstallation(CreditScoring $record): ?string
    {
        self::$installations ??= \Illuminate\Support\Facades\DB::table('installation')->where('systeme_installe', 1)->whereNotNull('id_client')
            ->whereIn('id_client', fn ($q) => $q->select('id_client')->from('assignation_kit')->where('statut', 'assigne'))
            ->groupBy('id_client')->selectRaw('id_client, max(date_installation) as date_installation')
            ->pluck('date_installation', 'id_client')->all();

        return ($date = self::$installations[$record->id_client] ?? null) ? Carbon::parse($date)->format('d/m/Y') : null;
    }

    private static function oublierCompteurs(): void
    {
        foreach (['a_assigner', 'pret_installation', 'installe'] as $onglet) {
            Cache::forget("kits-{$onglet}:".auth()->id());
        }
        self::$assignations = null;
    }

    /** Kit assigné au client de cette ligne (null : pas encore assigné). */
    public static function assignation(CreditScoring $record): ?AssignationKit
    {
        if (! AssignationKit::tableDisponible()) {
            return null;
        }

        self::$assignations ??= AssignationKit::query()->where('statut', 'assigne')->latest('id')->get()->keyBy('id_client')->all();

        return self::$assignations[$record->id_client] ?? null;
    }

    /** Champs du kit : quatre numéros de série, technicien prévu, date et commentaire. */
    private static function champsKit(?\Closure $assignationActuelle = null): array
    {
        // Un n° de série de pompe ne sert qu'à un seul kit en cours
        $pompeLibre = fn () => function (string $attribut, $valeur, \Closure $echec) use ($assignationActuelle) {
            $deja = AssignationKit::withoutGlobalScopes()->where('statut', 'assigne')
                ->whereRaw('upper(num_serie_pompe) = ?', [mb_strtoupper(trim((string) $valeur))])
                ->when($assignationActuelle && ($actuelle = $assignationActuelle()), fn ($q) => $q->whereKeyNot($actuelle->getKey()))
                ->first();
            if ($deja) {
                $echec('Cette pompe est déjà assignée au client '.$deja->id_client.'.');
            }
        };

        return [
            Section::make('Numéros de série du kit')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('num_serie_controleur')->label('N° de série du contrôleur')->required()->maxLength(60),
                    TextInput::make('num_serie_pompe')->label('N° de série de la pompe')->required()->maxLength(60)->rules([$pompeLibre]),
                    TextInput::make('num_serie_panneau_1')->label('N° de série du panneau 1')->required()->maxLength(60),
                    TextInput::make('num_serie_panneau_2')->label('N° de série du panneau 2')->maxLength(60)
                        ->helperText('Laisser vide si le kit n\'a qu\'un panneau.'),
                ]),
            Section::make('Installation')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Select::make('id_agent_technicien')->label('Technicien prévu')->options(fn () => Agent::options())->searchable()
                        ->helperText('Facultatif.'),
                    DatePicker::make('date_assignation')->label('Date d\'assignation')->native(false)->displayFormat('d/m/Y')
                        ->default(now())->required(),
                    Textarea::make('commentaire')->label('Commentaire')->rows(2)->columnSpanFull(),
                ]),
        ];
    }

    private function actionAssigner(): Action
    {
        return Action::make('assignerKit')
            ->label('Assigner le kit')
            ->icon(Heroicon::OutlinedCube)
            ->button()
            ->size('sm')
            ->authorize(fn () => \App\Support\Acces::peut('assignation_kits.modifier'))
            ->visible(fn (CreditScoring $record) => AssignationKit::tableDisponible() && ! self::assignation($record))
            ->modalHeading(fn (CreditScoring $record) => 'Assigner un kit · '.$record->client?->nom_complet)
            ->modalDescription(fn (CreditScoring $record) => collect([
                $record->libelleReponse('q12_cs') ? 'Kit : '.$record->libelleReponse('q12_cs') : null,
                $record->num_contrat_upya ? 'Contrat UPYA '.$record->num_contrat_upya : null,
            ])->filter()->implode(' · ') ?: null)
            ->modalSubmitActionLabel('Assigner le kit')
            ->modalWidth('2xl')
            ->schema(self::champsKit())
            ->action(function (CreditScoring $record, array $data) {
                AssignationKit::create($data + [
                    'id_client' => $record->id_client,
                    'id_cs' => $record->getKey(),
                    'num_contrat_upya' => $record->num_contrat_upya,
                    'statut' => 'assigne',
                    'assigne_par' => auth()->id(),
                    'nom_assigneur' => auth()->user()?->name,
                ]);
                self::oublierCompteurs();

                Notification::make()
                    ->title('Kit assigné')
                    ->body($record->client?->nom_complet.' est maintenant dans « Prêts pour l\'installation ».')
                    ->success()
                    ->send();
            });
    }

    /**
     * Rapport d'installation du technicien, depuis « Prêts pour l'installation ». Enregistré dans la table installation
     * comme les rapports AppSheet (mêmes libellés dans donnees_origine) : « Voir le rapport » l'affiche à l'identique.
     * Système installé : le client sort des listes en attente et passe dans « Assignés · installés ».
     */
    private function actionRapport(): Action
    {
        $ouiNon = ['1' => 'Oui', '0' => 'Non'];
        $photo = fn (string $nom, string $libelle) => \Filament\Forms\Components\FileUpload::make($nom)->label($libelle)
            ->image()->disk(\App\Formulaires\ConstructeurFormulaire::DISQUE_PHOTOS)
            ->directory('installation/'.now()->format('Y/m'))->maxSize(8192)->imagePreviewHeight('120')
            ->extraInputAttributes(['capture' => 'environment']);

        return Action::make('rapportInstallation')
            ->label('Rapport d\'installation')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('success')
            ->button()
            ->size('sm')
            ->authorize(fn () => \App\Support\Acces::peut('installations.creer'))
            ->visible(fn (CreditScoring $record) => (bool) self::assignation($record) && $this->liste() === 'pret_installation')
            ->modalHeading(fn (CreditScoring $record) => 'Rapport d\'installation · '.$record->client?->nom_complet)
            ->modalDescription(fn (CreditScoring $record) => collect([
                $record->libelleReponse('q12_cs') ? 'Kit : '.$record->libelleReponse('q12_cs') : null,
                $record->num_contrat_upya ? 'Contrat UPYA '.$record->num_contrat_upya : null,
            ])->filter()->implode(' · ') ?: null)
            ->modalSubmitActionLabel('Enregistrer le rapport')
            ->modalWidth('4xl')
            ->slideOver()
            ->fillForm(fn (CreditScoring $record) => [
                'date_installation' => now()->toDateString(),
                'systeme_installe' => '1',
                'kit_recu' => '1',
                'code_controleur' => self::assignation($record)?->num_serie_controleur,
                'num_serie_pompe' => self::assignation($record)?->num_serie_pompe,
                'num_serie_panneau_1' => self::assignation($record)?->num_serie_panneau_1,
                'num_serie_panneau_2' => self::assignation($record)?->num_serie_panneau_2,
                'assignee_a' => CacheReferences::libelle(Agent::class, self::assignation($record)?->id_agent_technicien) ?? auth()->user()?->name,
                'prise_en_charge_par' => auth()->user()?->name,
                'statut_technicien' => 'Staff',
            ])
            ->schema([
                Section::make('Installation')->columns(['default' => 1, 'md' => 3])->schema([
                    DatePicker::make('date_installation')->label('Date d\'installation')->native(false)->displayFormat('d/m/Y')->required(),
                    \Filament\Forms\Components\ToggleButtons::make('kit_recu')->label('Le kit a-t-il été reçu par le client ?')->options($ouiNon)->inline()->required(),
                    \Filament\Forms\Components\ToggleButtons::make('systeme_installe')->label('Le système a-t-il été installé ?')->options($ouiNon)
                        ->colors(['1' => 'success', '0' => 'danger'])->inline()->required()->live(),
                    TextInput::make('raison_non_installation')->label('Raison de la non-installation')->columnSpanFull()
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => (string) $get('systeme_installe') === '0')
                        ->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => (string) $get('systeme_installe') === '0'),
                    Textarea::make('cause_non_installation')->label('Précisions')->rows(2)->columnSpanFull()
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => (string) $get('systeme_installe') === '0'),
                    TextInput::make('geolocalisation')->label('Géolocalisation (lat, long)')->placeholder('6.110218, -5.937503')->columnSpanFull(),
                ]),
                Section::make('Kit installé (repris de l\'assignation, à corriger si besoin)')->columns(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('code_controleur')->label('N° de série du contrôleur')->required()->maxLength(60),
                    TextInput::make('num_serie_pompe')->label('N° de série de la pompe')->required()->maxLength(60),
                    TextInput::make('num_serie_panneau_1')->label('N° de série du panneau 1')->required()->maxLength(60),
                    TextInput::make('num_serie_panneau_2')->label('N° de série du panneau 2')->maxLength(60),
                ]),
                Section::make('Contrôles et formation')->columns(['default' => 1, 'md' => 3])
                    ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => (string) $get('systeme_installe') !== '0')
                    ->schema([
                        \Filament\Forms\Components\ToggleButtons::make('teste')->label('Le système a-t-il été testé ?')->options($ouiNon)->inline(),
                        \Filament\Forms\Components\ToggleButtons::make('pompe_testee')->label('La pompe a-t-elle été testée ?')->options($ouiNon)->inline(),
                        \Filament\Forms\Components\ToggleButtons::make('client_forme')->label('Le client a-t-il été formé ?')->options($ouiNon)->inline(),
                        Select::make('position_panneaux')->label('Position des panneaux')->options(['Sur le toit' => 'Sur le toit', 'Au sol' => 'Au sol', 'Sur support' => 'Sur support']),
                        TextInput::make('profondeur_source')->label('Profondeur de la source d\'eau (m)')->numeric(),
                        TextInput::make('profondeur_pompe')->label('Profondeur de réglage de la pompe (m)')->numeric(),
                        Select::make('qualite_eau')->label('La qualité d\'eau')->options(['Bonne' => 'Bonne', 'Moyenne' => 'Moyenne', 'Mauvaise' => 'Mauvaise']),
                    ]),
                Section::make('Technicien')->columns(['default' => 1, 'md' => 3])->schema([
                    TextInput::make('assignee_a')->label('Installation assignée à')->required()->maxLength(150),
                    TextInput::make('prise_en_charge_par')->label('Prise en charge par')->maxLength(150),
                    Select::make('statut_technicien')->label('Statut du technicien')->options(['Staff' => 'Staff', 'Freelance' => 'Freelance']),
                ]),
                Section::make('Photos')->columns(['default' => 1, 'md' => 3])->schema([
                    $photo('photo_installation', 'Photo de l\'installation'),
                    $photo('photo_client', 'Photo du client avec son équipement'),
                    $photo('photo_contrat', 'Photo du contrat signé'),
                ]),
            ])
            ->action(function (CreditScoring $record, array $data) {
                $client = $record->client;
                $oui = fn ($v) => $v === null || $v === '' ? null : ((string) $v === '1' ? 'OUI' : 'NON');
                $url = fn ($chemin) => $chemin ? \Illuminate\Support\Facades\Storage::disk(\App\Formulaires\ConstructeurFormulaire::DISQUE_PHOTOS)->url($chemin) : null;

                do {
                    $id = bin2hex(random_bytes(4));
                } while (\App\Models\Installation::withoutGlobalScopes()->whereKey($id)->exists());

                \App\Models\Installation::create([
                    'id_installation' => $id,
                    'id_client' => $record->id_client,
                    'date_installation' => $data['date_installation'],
                    'systeme_installe' => (int) $data['systeme_installe'],
                    'kit_recu' => (int) $data['kit_recu'],
                    'num_cni' => $client?->kyc?->num_piece_id,
                    'num_contrat' => \App\Models\Contrat::tableDisponible() ? $client?->contrats()->latest('date_contrat')->value('num_contrat') : null,
                    'num_contrat_upya' => $record->num_contrat_upya,
                    'type_produit' => $record->libelleReponse('q12_cs'),
                    'contact_client' => $client?->contact1_client,
                    'ville' => CacheReferences::libelle(Ville::class, $client?->ref(Localite::class, 'id_localite_commune')?->id_ville),
                    'localite' => $client?->ref(Localite::class, 'id_localite_commune')?->lib_localite,
                    'geolocalisation' => $data['geolocalisation'] ?? null,
                    'prise_en_charge_par' => $data['prise_en_charge_par'] ?? null,
                    'assignee_a' => $data['assignee_a'],
                    'statut_technicien' => $data['statut_technicien'] ?? null,
                    'code_controleur' => $data['code_controleur'],
                    'num_serie_pompe' => $data['num_serie_pompe'],
                    'num_serie_panneau_1' => $data['num_serie_panneau_1'],
                    'num_serie_panneau_2' => $data['num_serie_panneau_2'] ?? null,
                    'raison_non_installation' => $data['raison_non_installation'] ?? null,
                    'cause_non_installation' => $data['cause_non_installation'] ?? null,
                    // Mêmes libellés que les rapports AppSheet : « Voir le rapport » les affiche
                    'donnees_origine' => array_filter([
                        'ID installation' => $id,
                        'Saisi dans le CRM par' => auth()->user()?->name,
                        'Le KIT a t-il été reçu par le client ?' => $oui($data['kit_recu'] ?? null),
                        'Le système a-t-il été installé ?' => $oui($data['systeme_installe'] ?? null),
                        'le système a-t-il été testé ?' => $oui($data['teste'] ?? null),
                        'La pompe a-t-elle été testée ?' => $oui($data['pompe_testee'] ?? null),
                        'Le client a t-il été formé ?' => $oui($data['client_forme'] ?? null),
                        'Position des panneaux' => $data['position_panneaux'] ?? null,
                        'Profondeur de la source d\'eau (en mètres)' => $data['profondeur_source'] ?? null,
                        'Profondeur de réglage de la pompe (en mètres)' => $data['profondeur_pompe'] ?? null,
                        'La qualité d\'eau' => $data['qualite_eau'] ?? null,
                        'Photo de l\'installation' => $url($data['photo_installation'] ?? null),
                        'Photo du client avec sont equipement' => $url($data['photo_client'] ?? null),
                        'Photo de la première page du contrat' => $url($data['photo_contrat'] ?? null),
                    ], fn ($v) => filled($v)),
                ]);

                // Listes et compteurs à jour tout de suite
                foreach (['installations-compteurs-onglets', 'indicateurs-installations', 'table-installation-disponible'] as $cle) {
                    Cache::forget($cle);
                }
                self::oublierCompteurs();
                self::$installations = null;

                Notification::make()
                    ->title((int) $data['systeme_installe'] === 1 ? 'Installation enregistrée' : 'Rapport enregistré : non installé')
                    ->body((int) $data['systeme_installe'] === 1
                        ? $client?->nom_complet.' passe dans « Assignés · installés ».'
                        : 'Le client reste dans « Prêts pour l\'installation ».')
                    ->success()
                    ->send();
            });
    }

    private function actionModifier(): Action
    {
        return Action::make('modifierKit')
            ->label('Modifier le kit')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize(fn () => \App\Support\Acces::peut('assignation_kits.modifier'))
            ->visible(fn (CreditScoring $record) => (bool) self::assignation($record) && $this->liste() !== 'installe') // installé : plus rien à modifier
            ->modalHeading(fn (CreditScoring $record) => 'Kit assigné · '.$record->client?->nom_complet)
            ->modalWidth('2xl')
            ->fillForm(fn (CreditScoring $record) => self::assignation($record)?->only([
                'num_serie_controleur', 'num_serie_pompe', 'num_serie_panneau_1', 'num_serie_panneau_2',
                'id_agent_technicien', 'date_assignation', 'commentaire',
            ]) ?? [])
            ->schema(fn (CreditScoring $record) => self::champsKit(fn () => self::assignation($record)))
            ->action(function (CreditScoring $record, array $data) {
                self::assignation($record)?->update($data);
                self::oublierCompteurs();
                Notification::make()->title('Kit modifié')->success()->send();
            });
    }

    private function actionAnnuler(): Action
    {
        return Action::make('annulerKit')
            ->label('Annuler l\'assignation')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize(fn () => \App\Support\Acces::peut('assignation_kits.modifier'))
            ->visible(fn (CreditScoring $record) => (bool) self::assignation($record) && $this->liste() !== 'installe') // installé : plus rien à modifier
            ->requiresConfirmation()
            ->modalDescription('Le client repasse dans « À assigner ». Les numéros de série restent dans son journal.')
            ->action(function (CreditScoring $record) {
                self::assignation($record)?->update(['statut' => 'annule']);
                self::oublierCompteurs();
                Notification::make()->title('Assignation annulée')->success()->send();
            });
    }

    /** Dossiers dont le dernier appel du CX est « prêt pour l'installation ». */
    public static function requete(string $liste = 'a_assigner'): Builder
    {
        // Les clients installés sortent de la préparation : la liste « installés » part de tous les dossiers validés
        $base = $liste === 'installe'
            ? Preparation::getEloquentQuery()->whereIn((new CreditScoring)->qualifyColumn('id_client'),
                fn (\Illuminate\Database\Query\Builder $q) => $q->select('id_client')->from('installation')->where('systeme_installe', 1)->whereNotNull('id_client'))
            : Preparation::filtrerEtat(Preparation::getEloquentQuery(), 'pret');

        return self::filtrer($base
            ->selectRaw(sprintf(self::DERNIER_APPEL, 'date_prep').' AS date_pret')
            ->selectRaw(sprintf(self::DERNIER_APPEL, 'id_agent_traitant').' AS id_agent_cx'), $liste);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(), // onglets Non assignés / Assignés non installés / Assignés installés
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $kit = fn (string $colonne) => fn (CreditScoring $record) => self::assignation($record)?->{$colonne};

        return $table
            ->query(fn () => self::requete($this->liste()))
            ->defaultSort('date_pret', 'desc') // derniers clients prêts en haut
            ->paginated([25, 50, 100])
            ->recordUrl(fn (CreditScoring $record) => ClientResource::getUrl('view', ['record' => $record->id_client]))
            ->columns([
                // Une information par colonne
                TextColumn::make('client')
                    ->url(fn (CreditScoring $record) => \App\Support\LiensCrm::client($record->id_client)) // ouvre la fiche client
                    ->label('Client')
                    ->state(fn (CreditScoring $record) => $record->client?->nom_complet)
                    ->weight('semibold'),
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
                    ->state(fn (CreditScoring $record) => CacheReferences::libelle(SiteShop::class,
                        $record->client?->ref(Localite::class, 'id_localite_commune')?->id_site_shop))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('num_contrat_upya')
                    ->url(fn (CreditScoring $record) => \App\Support\LiensCrm::contratUpya($record->num_contrat_upya)) // ouvre le contrat
                    ->label('Contrat UPYA')
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('pompe')
                    ->label('Kit / pompe')
                    ->state(fn (CreditScoring $record) => $record->libelleReponse('q12_cs'))
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),
                TextColumn::make('date_pret')
                    ->label('Prêt depuis')
                    ->state(fn (CreditScoring $record) => $record->date_pret ? Carbon::parse($record->date_pret)->format('d/m/Y') : null)
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('attente')
                    ->label('Attente')
                    ->state(fn (CreditScoring $record) => $record->date_pret
                        ? (int) Carbon::parse($record->date_pret)->startOfDay()->diffInDays(now()->startOfDay()).' j'
                        : null)
                    ->placeholder('—'),
                TextColumn::make('id_agent_cx')
                    ->label('Validé par (CX)')
                    ->state(fn (CreditScoring $record) => CacheReferences::libelle(Agent::class, $record->id_agent_cx))
                    ->placeholder('—')
                    ->toggleable(),
                // Kit assigné (onglet « Prêts pour l'installation ») : une information par colonne
                TextColumn::make('kit_controleur')->label('N° contrôleur')->state($kit('num_serie_controleur'))
                    ->fontFamily('mono')->size('sm')->copyable()->placeholder('—')
                    ->visible(fn () => $this->avecKit()),
                TextColumn::make('kit_pompe')->label('N° pompe')->state($kit('num_serie_pompe'))
                    ->fontFamily('mono')->size('sm')->copyable()->placeholder('—')
                    ->visible(fn () => $this->avecKit()),
                TextColumn::make('kit_panneau_1')->label('N° panneau 1')->state($kit('num_serie_panneau_1'))
                    ->fontFamily('mono')->size('sm')->copyable()->placeholder('—')
                    ->visible(fn () => $this->avecKit()),
                TextColumn::make('kit_panneau_2')->label('N° panneau 2')->state($kit('num_serie_panneau_2'))
                    ->fontFamily('mono')->size('sm')->copyable()->placeholder('—')
                    ->visible(fn () => $this->avecKit()),
                TextColumn::make('kit_technicien')->label('Technicien')
                    ->state(fn (CreditScoring $record) => CacheReferences::libelle(Agent::class, self::assignation($record)?->id_agent_technicien))
                    ->placeholder('—')
                    ->visible(fn () => $this->avecKit()),
                TextColumn::make('kit_date')->label('Assigné le')
                    ->state(fn (CreditScoring $record) => self::assignation($record)?->date_assignation?->format('d/m/Y'))
                    ->placeholder('—')
                    ->visible(fn () => $this->avecKit()),
                TextColumn::make('installe_le')->label('Installé le')
                    ->state(fn (CreditScoring $record) => self::dateInstallation($record))
                    ->badge()->color('success')->placeholder('—')
                    ->visible(fn () => $this->liste() === 'installe'),
                TextColumn::make('kit_par')->label('Assigné par')->state($kit('nom_assigneur'))->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => $this->avecKit()),
            ])
            ->recordActions([
                $this->actionAssigner(),
                $this->actionRapport(),
                ActionGroup::make([$this->actionModifier(), $this->actionAnnuler()]),
            ])
            ->filters([
                SelectFilter::make('shop')
                    ->label('Shop')
                    ->options(fn () => SiteShop::options())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('client', fn (Builder $client) => $client->duShop((int) $data['value']))
                        : $query),
                SelectFilter::make('ville')
                    ->label('Ville')
                    ->options(fn () => Ville::options())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('client', fn (Builder $client) => $client->deLaVille((int) $data['value']))
                        : $query),
            ], layout: FiltersLayout::AboveContent)
            ->persistFiltersInSession()
            ->emptyStateHeading(fn () => match ($this->liste()) {
                'pret_installation' => 'Aucun kit assigné en attente d\'installation',
                'installe' => 'Aucun kit assigné déjà installé',
                default => 'Aucun client en attente de kit',
            })
            ->emptyStateDescription(fn () => match ($this->liste()) {
                'pret_installation' => 'Les clients dont le kit a été assigné apparaissent ici, jusqu\'à leur installation.',
                'installe' => 'Les clients dont le kit assigné a été installé (rapport « système installé ») apparaissent ici.',
                default => 'Les clients déclarés « prêts pour l\'installation » par le CX apparaissent ici.',
            })
            ->emptyStateIcon(Heroicon::OutlinedCube);
    }
}
