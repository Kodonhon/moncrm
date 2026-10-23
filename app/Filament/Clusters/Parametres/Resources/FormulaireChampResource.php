<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ParametresCluster;
use App\Filament\Clusters\Parametres\Resources\Pages\GererFormulaireClient;
use App\Formulaires\Formule;
use App\Formulaires\ListesReference;
use App\Models\FormulaireChamp;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FormulaireChampResource extends Resource
{
    use \App\Filament\Concerns\ControleAcces;

    protected static string $moduleAcces = 'parametres';

    public const FORMULAIRE = 'client';

    protected static ?string $model = FormulaireChamp::class;

    protected static ?string $cluster = ParametresCluster::class;

    protected static ?string $slug = 'formulaire-client';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Formulaire client';

    protected static ?string $modelLabel = 'question';

    protected static ?string $pluralModelLabel = 'questions du formulaire client';

    protected static ?string $recordTitleAttribute = 'libelle';

    public static function getTitleCasePluralModelLabel(): string
    {
        return 'Formulaire client';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('formulaire', static::FORMULAIRE);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Question')
                    ->columns(2)
                    ->schema([
                        TextInput::make('libelle')
                            ->label('Intitulé')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('section')
                            ->label('Section')
                            ->required()
                            ->datalist(fn () => static::sections())
                            ->helperText('Choisissez une section existante ou tapez un nouveau nom.'),
                        Select::make('type')
                            ->label('Type de réponse')
                            ->required()
                            ->live()
                            ->disabled(fn (?FormulaireChamp $record) => $record?->standard && ! $record->type_modifiable && ! $record->estUneListe())
                            ->helperText(fn (?FormulaireChamp $record) => match (true) {
                                ! $record?->standard => null,
                                (bool) $record->type_modifiable => 'Question d\'origine enregistrée en texte : texte, nombre, téléphone, liste… sont possibles.',
                                default => 'Question d\'origine : seul le passage liste ↔ boutons est possible.',
                            })
                            ->options(fn (?FormulaireChamp $record) => match (true) {
                                ! $record?->standard => FormulaireChamp::TYPES,
                                (bool) $record->type_modifiable => array_intersect_key(FormulaireChamp::TYPES, array_flip(FormulaireChamp::TYPES_TEXTE)),
                                $record->estUneListe() => array_intersect_key(FormulaireChamp::TYPES, array_flip(FormulaireChamp::TYPES_LISTE)),
                                default => FormulaireChamp::TYPES,
                            }),
                        TextInput::make('defaut')
                            ->label('Valeur par défaut')
                            ->helperText('Date : « aujourdhui ». Liste : le n° ou la valeur du choix. « client.colonne » (ex. client.entite_id_client) reprend l\'information de la fiche du client, non modifiable.'),
                        TextInput::make('aide')
                            ->label('Texte d\'aide')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Grid::make(3)->columnSpanFull()->schema([
                            Toggle::make('visible')
                                ->label('Affichée')
                                ->default(true)
                                ->disabled(fn (?FormulaireChamp $record) => (bool) $record?->verrouille),
                            Toggle::make('obligatoire')
                                ->label('Obligatoire'),
                            Toggle::make('pleine_largeur')
                                ->label('Pleine largeur'),
                        ]),
                    ]),

                Section::make('Liste de choix')
                    ->visible(fn (Get $get) => in_array($get('type'), FormulaireChamp::TYPES_LISTE, true))
                    ->schema([
                        Select::make('source_liste')
                            ->label('Choix proposés')
                            ->options(ListesReference::choixSources())
                            ->default(ListesReference::PERSONNALISEE)
                            ->required(fn (Get $get) => in_array($get('type'), FormulaireChamp::TYPES_LISTE, true))
                            ->live()
                            ->disabled(fn (?FormulaireChamp $record) => $record?->standard && $record->source_liste !== ListesReference::PERSONNALISEE)
                            ->helperText('Les tables de référence se gèrent dans les autres écrans de Paramètres.'),
                        Repeater::make('options')
                            ->label('Choix de la liste')
                            ->visible(fn (Get $get) => $get('source_liste') === ListesReference::PERSONNALISEE)
                            ->schema([
                                TextInput::make('valeur')->label('Valeur enregistrée')->required(),
                                TextInput::make('libelle')->label('Libellé affiché')->placeholder('Identique à la valeur'),
                                TextInput::make('parent')
                                    ->label('Valeur de la question liée')
                                    ->placeholder('Toujours proposé')
                                    ->visible(fn (Get $get) => filled($get('../../liste_parent'))),
                            ])
                            ->columns(3)
                            ->reorderable()
                            ->addActionLabel('Ajouter un choix')
                            ->defaultItems(1),
                        Grid::make(2)->schema([
                            Select::make('liste_parent')
                                ->label('Filtrer selon la réponse à')
                                ->placeholder('Aucune (liste complète)')
                                ->options(fn (?FormulaireChamp $record) => self::autresQuestions($record, listesSeulement: true))
                                ->live(),
                            Select::make('liste_parent_colonne')
                                ->label('Correspondance')
                                ->options(fn (Get $get) => ListesReference::filtresPossibles($get('source_liste')))
                                ->visible(fn (Get $get) => filled($get('liste_parent')) && $get('source_liste') !== ListesReference::PERSONNALISEE)
                                ->required(fn (Get $get) => filled($get('liste_parent')) && $get('source_liste') !== ListesReference::PERSONNALISEE)
                                ->helperText('Colonne de la liste comparée à la réponse de la question liée.'),
                        ]),
                    ]),

                Section::make('Formule de calcul')
                    ->visible(fn (Get $get) => $get('type') === 'calcul')
                    ->description('Le résultat se met à jour tout seul dans le formulaire et est enregistré avec les réponses.')
                    ->schema([
                        Textarea::make('formule')
                            ->label('Formule')
                            ->rows(3)
                            ->required(fn (Get $get) => $get('type') === 'calcul')
                            ->placeholder('{prof_reglage_pomp_tdh} + {haut_elevation_verticale_tdh} + 0,1 * {dist_src_eau_et_reservoir_tdh}')
                            ->helperText('Questions entre accolades { }, opérations + − × ÷ ( ), décimales avec une virgule, fonctions min(a; b), max(a; b), abs(a), arrondi(a; 2). Une question vide vaut 0.')
                            ->rule(fn () => function (string $attribut, mixed $valeur, \Closure $echec) {
                                if ($erreur = Formule::erreur((string) $valeur)) {
                                    $echec('Formule incorrecte : '.$erreur);
                                }

                                $inconnues = array_diff(Formule::variables((string) $valeur), FormulaireChamp::pour(static::FORMULAIRE)->pluck('cle')->all());
                                if ($inconnues) {
                                    $echec('Questions inconnues : '.implode(', ', $inconnues).'.');
                                }
                            }),
                        TextEntry::make('cles_disponibles')
                            ->label('Questions utilisables (nombres)')
                            ->state(fn () => FormulaireChamp::pour(static::FORMULAIRE)
                                ->filter(fn (FormulaireChamp $champ) => in_array($champ->type, ['nombre', 'calcul', 'oui_non'], true))
                                ->map(fn (FormulaireChamp $champ) => '{'.$champ->cle.'} : '.$champ->libelle)
                                ->values()
                                ->all())
                            ->listWithLineBreaks()
                            ->copyable()
                            ->color('gray')
                            ->size('sm'),
                    ]),

                Section::make('Conditions d\'affichage')
                    ->description('La question n\'apparaît que si toutes les conditions sont vraies. Sans condition : toujours affichée.')
                    ->collapsible()
                    ->schema([
                        Repeater::make('conditions')
                            ->hiddenLabel()
                            ->schema([
                                Select::make('champ')
                                    ->label('Si la question')
                                    ->options(fn (?FormulaireChamp $record) => self::autresQuestions($record))
                                    ->required()
                                    ->live(),
                                Select::make('operateur')
                                    ->label('')
                                    ->options(FormulaireChamp::OPERATEURS)
                                    ->default('egal')
                                    ->required()
                                    ->live(),
                                Select::make('valeurs')
                                    ->label('Valeur(s)')
                                    ->multiple()
                                    ->options(fn (Get $get) => self::optionsQuestion($get('champ')))
                                    ->visible(fn (Get $get) => in_array($get('operateur'), ['egal', 'different'], true) && self::optionsQuestion($get('champ')) !== null)
                                    ->required(fn (Get $get) => in_array($get('operateur'), ['egal', 'different'], true)),
                                TagsInput::make('valeurs')
                                    ->label('Valeur(s)')
                                    ->placeholder('Tapez puis Entrée')
                                    ->visible(fn (Get $get) => in_array($get('operateur'), ['egal', 'different'], true) && self::optionsQuestion($get('champ')) === null)
                                    ->required(fn (Get $get) => in_array($get('operateur'), ['egal', 'different'], true)),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Ajouter une condition'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('ordre')
            ->reorderable('ordre')
            ->paginated(false)
            ->columns([
                TextColumn::make('libelle')
                    ->label('Question')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('cle')
                    ->label('Champ')
                    ->state(fn (FormulaireChamp $record) => $record->standard ? $record->cle : 'Question ajoutée')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('section')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('type')
                    ->formatStateUsing(fn (string $state) => FormulaireChamp::TYPES[$state] ?? $state)
                    ->color('gray'),
                TextColumn::make('regles')
                    ->label('Règles')
                    ->state(fn (FormulaireChamp $record) => self::resumeRegles($record))
                    ->listWithLineBreaks()
                    ->color('gray')
                    ->size('sm')
                    ->placeholder('—'),
                ToggleColumn::make('visible')
                    ->label('Affichée')
                    ->alignCenter()
                    ->disabled(fn (FormulaireChamp $record) => $record->verrouille),
                ToggleColumn::make('obligatoire')
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('section')
                    ->options(fn () => array_combine(static::sections(), static::sections())),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->modalWidth('3xl'),
                DeleteAction::make()
                    ->iconButton()
                    ->visible(fn (FormulaireChamp $record) => ! $record->standard)
                    ->modalDescription('Les réponses déjà saisies sont conservées dans la base, mais ne seront plus affichées.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => GererFormulaireClient::route('/'),
        ];
    }

    /** [clé => intitulé] des autres questions du formulaire. */
    public static function autresQuestions(?FormulaireChamp $record, bool $listesSeulement = false): array
    {
        return FormulaireChamp::pour(static::FORMULAIRE)
            ->when($record, fn ($champs) => $champs->reject(fn (FormulaireChamp $champ) => $champ->getKey() === $record->getKey()))
            ->when($listesSeulement, fn ($champs) => $champs->filter(fn (FormulaireChamp $champ) => $champ->estUneListe()))
            ->pluck('libelle', 'cle')
            ->all();
    }

    /** Sections existantes du formulaire. */
    protected static function sections(): array
    {
        return FormulaireChamp::pour(static::FORMULAIRE)->pluck('section')->unique()->sort()->values()->all();
    }

    /** Choix possibles d'une question de type liste (null si la question n'est pas une liste). */
    public static function optionsQuestion(?string $cle): ?array
    {
        $champ = $cle ? FormulaireChamp::pour(static::FORMULAIRE)->firstWhere('cle', $cle) : null;

        if (! $champ) {
            return null;
        }

        if ($champ->type === 'oui_non') {
            return ['1' => 'Oui', '0' => 'Non'];
        }

        if (! $champ->estUneListe()) {
            return null;
        }

        if ($champ->source_liste === ListesReference::PERSONNALISEE) {
            return collect($champ->options ?? [])->mapWithKeys(fn ($option) => [$option['valeur'] => $option['libelle'] ?: $option['valeur']])->all();
        }

        return ListesReference::options($champ->source_liste);
    }

    protected static function resumeRegles(FormulaireChamp $record): array
    {
        $libelles = FormulaireChamp::pour(static::FORMULAIRE)->pluck('libelle', 'cle');
        $regles = [];

        if ($record->liste_parent) {
            $regles[] = 'Liste filtrée par « '.($libelles[$record->liste_parent] ?? $record->liste_parent).' »';
        }

        foreach ($record->conditions ?? [] as $condition) {
            $regles[] = 'Si « '.($libelles[$condition['champ']] ?? $condition['champ']).' » '
                .(FormulaireChamp::OPERATEURS[$condition['operateur']] ?? $condition['operateur'])
                .(in_array($condition['operateur'], ['egal', 'different'], true) ? ' '.implode(' ou ', $condition['valeurs'] ?? []) : '');
        }

        if ($record->type === 'calcul') {
            $regles[] = '= '.($record->formule ?: '(formule à saisir)');
        }

        if ($record->stockage === 'aucun') {
            $regles[] = 'Non enregistrée (sert de filtre)';
        }

        return $regles;
    }
}
