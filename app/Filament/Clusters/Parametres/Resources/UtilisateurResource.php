<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ParametresCluster;
use App\Filament\Clusters\Parametres\Resources\Pages\CreerUtilisateur;
use App\Filament\Clusters\Parametres\Resources\Pages\ListeUtilisateurs;
use App\Filament\Clusters\Parametres\Resources\Pages\ModifierUtilisateur;
use App\Filament\Concerns\ControleAcces;
use App\Models\Agent;
use App\Models\Entite;
use App\Models\Role;
use App\Models\User;
use App\Support\Acces;
use App\Support\CacheReferences;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Paramètres > Utilisateurs : comptes de connexion, agent rattaché, rôles et entités autorisées. */
class UtilisateurResource extends Resource
{
    use ControleAcces;

    protected static string $moduleAcces = 'acces';

    protected static ?string $model = User::class;

    protected static ?string $cluster = ParametresCluster::class;

    protected static ?string $slug = 'utilisateurs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Utilisateurs';

    protected static ?string $modelLabel = 'utilisateur';

    protected static ?string $pluralModelLabel = 'utilisateurs';

    protected static ?int $navigationSort = 51;

    public static function canAccess(): bool
    {
        return Acces::installe() && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return Acces::peut('acces.modifier');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // un compte se désactive, il ne se supprime pas (historique)
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->components([
                Section::make('Compte')
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nom affiché')->required()->maxLength(150),
                        TextInput::make('email')->label('E-mail (identifiant)')->email()->required()->maxLength(190)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label(fn (?User $record) => $record ? 'Nouveau mot de passe' : 'Mot de passe')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->required(fn (?User $record) => ! $record)
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText(fn (?User $record) => $record ? 'Laissez vide pour ne pas le changer.' : '8 caractères minimum.'),
                        Toggle::make('actif')
                            ->label('Compte actif')
                            ->default(true)
                            ->inline(false)
                            ->disabled(fn (?User $record) => $record?->is(auth()->user()))
                            ->helperText('Un compte inactif ne peut plus se connecter.'),
                    ]),
                Section::make('Rattachement')
                    ->icon(Heroicon::OutlinedLink)
                    ->description('L\'agent détermine « ses clients » et « son équipe » ; les entités limitent les données visibles.')
                    ->schema([
                        Select::make('id_agent')
                            ->label('Agent')
                            ->relationship('agent', 'nom_complet_agent')
                            ->searchable()
                            ->helperText('Fiche agent de cette personne (Paramètres > Gestion des agents).'),
                        CheckboxList::make('entites_autorisees')
                            ->label('Entités autorisées')
                            ->options(fn () => Entite::options())
                            ->columns(3)
                            ->helperText('Aucune case cochée : selon le rôle (toutes les entités, ou celle de son agent).'),
                    ]),
                Section::make('Rôles')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->columnSpanFull()
                    ->description('Les permissions s\'additionnent ; les données visibles sont celles du rôle le plus large.')
                    ->schema([
                        CheckboxList::make('roles')
                            ->hiddenLabel()
                            ->relationship('roles', 'nom')
                            ->descriptions(fn () => Role::query()->get()->mapWithKeys(fn (Role $r) => [
                                $r->id => trim(($r->description ? $r->description.' ' : '').'· Données : '.(Acces::PERIMETRES[$r->perimetre][0] ?? $r->perimetre)),
                            ])->all())
                            ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->required()
                            ->validationMessages(['required' => 'Choisissez au moins un rôle : sans rôle, l\'utilisateur ne peut pas se connecter.']),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->defaultSort('created_at', 'desc') // derniers comptes créés en haut
            ->columns([
                TextColumn::make('name')
                    ->label('Utilisateur')
                    ->weight('semibold')
                    ->searchable(['name', 'email']),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->copyable(),
                TextColumn::make('id_agent')
                    ->label('Agent')
                    ->formatStateUsing(fn ($state) => CacheReferences::libelle(Agent::class, $state))
                    ->placeholder('Non rattaché')
                    ->color(fn ($state) => $state ? null : 'warning'),
                TextColumn::make('roles.nom')
                    ->label('Rôles')
                    ->badge()
                    ->color(fn (string $state, User $record) => RoleResource::couleurFilament($record->roles->firstWhere('nom', $state)?->couleur))
                    ->placeholder('Aucun rôle'),
                TextColumn::make('entites_autorisees')
                    ->label('Entités')
                    ->state(fn (User $record) => collect($record->entites_autorisees ?? [])
                        ->map(fn ($id) => CacheReferences::libelle(Entite::class, $id))->filter()->implode(', ') ?: null)
                    ->placeholder('Selon le rôle')
                    ->toggleable(),
                TextColumn::make('derniere_connexion')
                    ->label('Dernière connexion')
                    ->since()
                    ->placeholder('Jamais')
                    ->sortable(),
                ToggleColumn::make('actif')
                    ->label('Actif')
                    ->disabled(fn (User $record) => $record->is(auth()->user()) || ! Acces::peut('acces.modifier'))
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('roles')->label('Rôle')->relationship('roles', 'nom')->multiple(),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListeUtilisateurs::route('/'),
            'create' => CreerUtilisateur::route('/create'),
            'edit' => ModifierUtilisateur::route('/{record}/edit'),
        ];
    }
}
