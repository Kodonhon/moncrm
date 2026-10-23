<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ParametresCluster;
use App\Filament\Clusters\Parametres\Resources\Pages\CreerRole;
use App\Filament\Clusters\Parametres\Resources\Pages\ListeRoles;
use App\Filament\Clusters\Parametres\Resources\Pages\ModifierRole;
use App\Filament\Concerns\ControleAcces;
use App\Models\Role;
use App\Support\Acces;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Paramètres > Rôles et permissions : ce que chaque rôle peut faire, et quelles données il voit. */
class RoleResource extends Resource
{
    use ControleAcces;

    protected static string $moduleAcces = 'acces';

    protected static ?string $model = Role::class;

    protected static ?string $cluster = ParametresCluster::class;

    protected static ?string $slug = 'roles';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Rôles et permissions';

    protected static ?string $modelLabel = 'rôle';

    protected static ?string $pluralModelLabel = 'rôles';

    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        return Acces::installe() && static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return Acces::peut('acces.modifier') && ! $record->systeme && $record->utilisateurs()->doesntExist();
    }

    public static function couleurFilament(?string $couleur): string
    {
        return ['vert' => 'success', 'violet' => 'info', 'orange' => 'warning', 'rouge' => 'danger'][$couleur] ?? 'gray';
    }

    public static function form(Schema $schema): Schema
    {
        $matrice = collect(Acces::MODULES)->map(fn (array $module, string $cle) => CheckboxList::make("permissions.{$cle}")
            ->label($module[0])
            ->helperText($module[1])
            ->options(collect($module[2])->mapWithKeys(fn ($action) => [$action => Acces::ACTIONS[$action]])->all())
            ->columns(3)
            ->gridDirection('row')
            ->bulkToggleable()
            ->disabled(fn (?Role $record) => $record?->estAdministrateur())
            ->extraAttributes(['class' => 'gr-matrice-module'])
        )->values()->all();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Rôle')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
                    ->schema([
                        TextInput::make('nom')
                            ->label('Nom du rôle')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Set $set, ?Role $record) => $record ? null : $set('code', Str::slug((string) $state, '_'))),
                        TextInput::make('code')
                            ->label('Code')
                            ->helperText('Identifiant technique, sans espace.')
                            ->required()
                            ->maxLength(50)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?Role $record) => (bool) $record?->systeme),
                        Textarea::make('description')->label('Description')->rows(2)->maxLength(255)->columnSpanFull(),
                        ToggleButtons::make('couleur')
                            ->label('Couleur')
                            ->options(Acces::COULEURS)
                            ->colors(['vert' => 'success', 'violet' => 'info', 'orange' => 'warning', 'rouge' => 'danger', 'gris' => 'gray'])
                            ->default('violet')
                            ->inline(),
                        Radio::make('perimetre')
                            ->label('Données visibles')
                            ->options(collect(Acces::PERIMETRES)->map(fn ($p) => $p[0])->all())
                            ->descriptions(collect(Acces::PERIMETRES)->map(fn ($p) => $p[1])->all())
                            ->default('personnel')
                            ->required(),
                    ]),
                Section::make('Permissions')
                    ->icon(Heroicon::OutlinedKey)
                    ->description(fn (?Role $record) => $record?->estAdministrateur()
                        ? 'Le rôle Administrateur a toutes les permissions ; elles ne se modifient pas.'
                        : 'Cochez ce que ce rôle peut faire dans chaque module. Sans « Consulter », le module n\'apparaît pas dans le menu.')
                    ->schema([Grid::make(['default' => 1, 'lg' => 2])->schema($matrice)]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['utilisateurs', 'permissions']))
            ->defaultSort('created_at', 'desc') // derniers créés en haut
            ->searchPlaceholder('Rechercher un rôle…')
            ->recordUrl(fn (Role $record) => static::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('nom')
                    ->label('Titre')
                    ->weight('medium')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(60)
                    ->tooltip(fn (Role $record) => $record->description)
                    ->color('info')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('statut')
                    ->label('Statut')
                    ->state(fn (Role $record) => $record->utilisateurs_count > 0 || $record->systeme ? 'Actif' : 'Sans utilisateur')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Actif' ? 'success' : 'gray'),
                TextColumn::make('cree_par')
                    ->label('Créé par')
                    ->state(fn (Role $record) => $record->systeme ? 'Système' : 'Administrateur')
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('perimetre')
                    ->label('Données visibles')
                    ->formatStateUsing(fn ($state) => Acces::PERIMETRES[$state][0] ?? $state)
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->formatStateUsing(fn ($state, Role $record) => $record->estAdministrateur()
                        ? 'Toutes'
                        : $state.' / '.count(Acces::toutesLesPermissions()))
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('utilisateurs_count')
                    ->label('Utilisateurs')
                    ->numeric(locale: 'fr')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('systeme')
                    ->label('Protégé')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedLockClosed)
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Icône de modification en début de ligne, comme sur SunCulture
            ->recordActionsPosition(\Filament\Tables\Enums\RecordActionsPosition::BeforeColumns)
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Modifier le rôle'),
                \Filament\Actions\Action::make('permissions')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedKey)
                    ->tooltip('Gérer les permissions')
                    ->url(fn (Role $record) => \App\Filament\Clusters\Parametres\Pages\PermissionsRoles::getUrl(['role' => $record->getKey()])),
                DeleteAction::make()->iconButton(),
            ]);
    }

    /** Permissions groupées par module, pour remplir la matrice. */
    public static function versMatrice(Role $role): array
    {
        $permissions = $role->estAdministrateur() ? Acces::toutesLesPermissions() : $role->listePermissions();

        return collect($permissions)
            ->map(fn ($p) => explode('.', $p, 2))
            ->filter(fn ($p) => count($p) === 2)
            ->groupBy(0)
            ->map(fn ($groupe) => $groupe->pluck(1)->values()->all())
            ->all();
    }

    /** Matrice du formulaire → liste « module.action ». */
    public static function depuisMatrice(array $matrice): array
    {
        return collect($matrice)
            ->flatMap(fn ($actions, $module) => collect((array) $actions)->map(fn ($a) => "{$module}.{$a}"))
            ->values()
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListeRoles::route('/'),
            'create' => CreerRole::route('/create'),
            'edit' => ModifierRole::route('/{record}/edit'),
        ];
    }
}
