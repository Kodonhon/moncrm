<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ParametresCluster;
use App\Filament\Clusters\Parametres\Resources\Pages\GererAgents;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Models\Agent;
use App\Models\Entite;
use App\Models\SiteShop;
use App\Support\CacheReferences;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

/**
 * Paramètres > Gestion des agents : liste complète des agents (fichier RH d'origine + agents créés dans le CRM),
 * avec entité, supérieur, contacts, dates et nombre de clients enregistrés.
 */
class AgentResource extends Resource
{
    use \App\Filament\Concerns\ControleAcces;

    protected static string $moduleAcces = 'agents';

    protected static ?string $model = Agent::class;

    protected static ?string $cluster = ParametresCluster::class;

    protected static ?string $slug = 'agents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'agent';

    protected static ?string $pluralModelLabel = 'agents';

    protected static ?string $navigationLabel = 'Gestion des agents';

    protected static ?string $recordTitleAttribute = 'nom_complet_agent';

    public static function getTitleCasePluralModelLabel(): string
    {
        return 'Gestion des agents';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'md' => 2])
            ->components([
                Section::make('Identité')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('nom_agent')->label('Nom')->required()->maxLength(150)->autofocus(),
                        TextInput::make('prenoms_agent')->label('Prénoms')->maxLength(150),
                        Select::make('genre_agent')->label('Genre')->options(Agent::GENRES),
                        Toggle::make('visibilite_agent')->label('Actif')->default(true)->inline(false)
                            ->helperText('Un agent inactif n\'est plus proposé dans les listes.'),
                    ]),
                Section::make('Contact')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('contact_agent')->label('Téléphone')->tel()->maxLength(20),
                        TextInput::make('email_agent')->label('E-mail')->email()->maxLength(150),
                    ]),
                Section::make('Affectation')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('id_entite')->label('Entité')
                            ->options(fn (?Agent $record) => Entite::options($record?->id_entite)),
                        Select::make('sup_agent_id')->label('Supérieur')
                            ->relationship('superieur', 'nom_complet_agent', fn (Builder $query) => $query->where('visibilite_agent', 1))
                            ->searchable()
                            ->preload(false),
                        Select::make('id_site_shop')->label('Site / shop')
                            ->options(fn (?Agent $record) => SiteShop::options($record?->id_site_shop)),
                        Select::make('role_agent')->label('Rôle CRM')
                            ->options(Agent::ROLES)
                            ->default('terrain')
                            ->visible(fn () => \App\Models\CreditScoring::tableDisponible()),
                        TextInput::make('id_fonction_agent')->label('Code fonction')->numeric(),
                        TextInput::make('id_type_agent')->label('Code type')->numeric(),
                        TextInput::make('id_zone_agent')->label('Code zone')->numeric(),
                        TextInput::make('id_dpt')->label('Code département')->numeric(),
                    ]),
                Section::make('Dates')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('date_entree_agent')->label('Entrée')->displayFormat('d/m/Y')->native(false),
                        DatePicker::make('date_elearning_agent')->label('E-learning')->displayFormat('d/m/Y')->native(false),
                        DatePicker::make('date_sortie_agent')->label('Sortie')->displayFormat('d/m/Y')->native(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id_agent', 'desc') // derniers agents ajoutés en haut
            // Nombres de clients et de subordonnés comptés dans la requête de la liste (pas une requête par ligne)
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['clients', 'subordonnes'])->with('superieur:id_agent,nom_complet_agent'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('id_agent')
                    ->label('N°')
                    ->sortable()
                    ->searchable()
                    ->width('5rem'),
                TextColumn::make('nom_complet_agent')
                    ->label('Agent')
                    ->weight('medium')
                    ->sortable()
                    ->searchable(['nom_agent', 'prenoms_agent', 'email_agent']),
                TextColumn::make('email_agent')
                    ->label('E-mail')
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('id_entite')
                    ->label('Entité')
                    ->formatStateUsing(fn ($state) => CacheReferences::libelle(Entite::class, $state))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('role_agent')
                    ->label('Rôle')
                    ->formatStateUsing(fn (?string $state) => Agent::ROLES[$state] ?? $state)
                    ->badge()
                    ->color(fn (?string $state) => $state === 'credit' ? 'info' : 'gray')
                    ->sortable()
                    ->visible(fn () => \App\Models\CreditScoring::tableDisponible()),
                TextColumn::make('superieur.nom_complet_agent')
                    ->label('Supérieur')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('contact_agent')
                    ->label('Téléphone')
                    ->formatStateUsing(fn ($state) => ClientsTable::formaterTelephone($state))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('id_zone_agent')->label('Zone')->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id_fonction_agent')->label('Fonction')->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id_type_agent')->label('Type')->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('date_entree_agent')
                    ->label('Entrée')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('clients_count')
                    ->label('Clients')
                    ->numeric(locale: 'fr')
                    ->alignEnd()
                    ->sortable(),
                ToggleColumn::make('visibilite_agent')
                    ->label('Actif')
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('id_entite')
                    ->label('Entité')
                    ->options(fn () => Entite::tousLesLibelles()),
                SelectFilter::make('role_agent')
                    ->label('Rôle')
                    ->options(Agent::ROLES)
                    ->visible(fn () => \App\Models\CreditScoring::tableDisponible()),
                SelectFilter::make('id_zone_agent')
                    ->label('Zone')
                    ->options(fn () => Agent::query()->whereNotNull('id_zone_agent')->distinct()->orderBy('id_zone_agent')
                        ->pluck('id_zone_agent', 'id_zone_agent')->map(fn ($z) => 'Zone '.$z)->all()),
                SelectFilter::make('sup_agent_id')
                    ->label('Supérieur')
                    ->relationship('superieur', 'nom_complet_agent')
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->modalWidth('3xl'),
                DeleteAction::make()
                    ->iconButton()
                    ->visible(fn (Agent $record) => (int) $record->clients_count === 0 && (int) $record->subordonnes_count === 0)
                    // Agent cité ailleurs (KYC, dimensionnement, scoring, contrat…) : on explique au lieu d'une erreur de base
                    ->using(function (Agent $record, DeleteAction $action) {
                        try {
                            return $record->delete();
                        } catch (QueryException) {
                            Notification::make()
                                ->title('Cet agent ne peut pas être supprimé')
                                ->body('Il est utilisé dans des KYC, dimensionnements, scorings ou contrats. Désactivez-le plutôt (colonne « Actif »).')
                                ->danger()
                                ->persistent()
                                ->send();
                            $action->halt();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => GererAgents::route('/'),
        ];
    }
}
