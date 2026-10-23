<?php

namespace App\Filament\Clusters\Parametres;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Écran générique d'une table de référence : liste, ajout, modification et activation/désactivation.
 * Un élément déjà utilisé ne peut pas être supprimé : on le désactive (il disparaît des listes de choix
 * mais reste affiché sur les fiches existantes).
 */
abstract class ReferenceResource extends Resource
{
    use \App\Filament\Concerns\ControleAcces;

    protected static string $moduleAcces = 'parametres';

    protected static ?string $cluster = ParametresCluster::class;

    /** Relation comptée pour afficher le nombre d'utilisations (ex. « clients »). */
    protected static ?string $relationUtilisations = null;

    protected static string $libelleUtilisations = 'Utilisations';

    /** Champs propres à la table, en plus du libellé et de l'activation. */
    protected static function champsSupplementaires(): array
    {
        return [];
    }

    /** Colonnes propres à la table, affichées entre le libellé et l'activation. */
    protected static function colonnesSupplementaires(): array
    {
        return [];
    }

    protected static function filtresSupplementaires(): array
    {
        return [];
    }

    /** « Canaux de vente » plutôt que « Canaux De Vente » (menu et titre de page). */
    public static function getNavigationLabel(): string
    {
        return static::getTitleCasePluralModelLabel();
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return ucfirst(static::getPluralModelLabel());
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return static::getModel()::COLONNE_LIBELLE;
    }

    public static function form(Schema $schema): Schema
    {
        $modele = static::getModel();

        return $schema
            ->columns(1)
            ->components([
                TextInput::make($modele::COLONNE_LIBELLE)
                    ->label('Libellé')
                    ->required()
                    ->maxLength(100)
                    ->autofocus(),
                ...static::champsSupplementaires(),
                Toggle::make($modele::COLONNE_VISIBILITE)
                    ->label('Actif')
                    ->helperText('Un élément inactif n\'est plus proposé dans les listes de choix.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        $modele = static::getModel();
        $cle = (new $modele)->getKeyName();
        $relation = static::$relationUtilisations;

        return $table
            ->defaultSort($cle, 'desc') // derniers ajoutés en haut
            ->modifyQueryUsing(fn (Builder $query) => $relation ? $query->withCount($relation) : $query)
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make($cle)
                    ->label('N°')
                    ->sortable()
                    ->searchable()
                    ->width('5rem'),
                TextColumn::make($modele::COLONNE_LIBELLE)
                    ->label('Libellé')
                    ->sortable()
                    ->searchable(),
                ...static::colonnesSupplementaires(),
                ...($relation ? [
                    TextColumn::make("{$relation}_count")
                        ->label(static::$libelleUtilisations)
                        ->numeric(locale: 'fr')
                        ->alignEnd()
                        ->sortable(),
                ] : []),
                ToggleColumn::make($modele::COLONNE_VISIBILITE)
                    ->label('Actif')
                    ->alignCenter(),
            ])
            ->filters([
                TernaryFilter::make($modele::COLONNE_VISIBILITE)
                    ->label('Statut')
                    ->placeholder('Tous')
                    ->trueLabel('Actifs')
                    ->falseLabel('Inactifs'),
                ...static::filtresSupplementaires(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->modalWidth('lg'),
                DeleteAction::make()
                    ->iconButton()
                    ->visible(fn (Model $record) => ! $relation || (int) $record->{"{$relation}_count"} === 0),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => static::pageGestion()::route('/'),
        ];
    }

    /** @return class-string<\Filament\Resources\Pages\ManageRecords> */
    abstract protected static function pageGestion(): string;
}
