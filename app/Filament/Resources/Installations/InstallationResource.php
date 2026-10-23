<?php

namespace App\Filament\Resources\Installations;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Schemas\ClientInfolist;
use App\Filament\Resources\Installations\Pages\ListeInstallations;
use App\Models\Client;
use App\Models\Installation;
use App\Support\Acces;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Rapports d'installation des techniciens (import AppSheet) : installés / non installés,
 * avec le détail de chaque rapport (numéros de série, contrôles, photos).
 */
class InstallationResource extends Resource
{
    protected static ?string $model = Installation::class;

    protected static ?string $slug = 'installations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $navigationLabel = 'Installations';

    protected static ?string $modelLabel = 'rapport d\'installation';

    protected static ?string $pluralModelLabel = 'installations';

    protected static ?int $navigationSort = 7;

    protected static string|\UnitEnum|null $navigationGroup = 'Opérations';

    public static function canViewAny(): bool
    {
        return Installation::tableDisponible() && Acces::peut('installations.voir');
    }

    public static function canCreate(): bool
    {
        return false; // les rapports viennent de l'application des techniciens
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('client');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date_installation', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordAction('voirRapport')
            ->searchPlaceholder('Rechercher : client, pièce, n° de série, technicien…')
            ->columns(self::colonnes())
            ->filters([
                SelectFilter::make('type_produit')
                    ->label('Produit installé')
                    ->options(fn () => Installation::query()->whereNotNull('type_produit')->distinct()->orderBy('type_produit')->pluck('type_produit', 'type_produit')->all())
                    ->searchable(),
                SelectFilter::make('type_paiement')
                    ->label('Paiement')
                    ->options(['PAYG' => 'PAYG', 'CASH' => 'Cash']),
                SelectFilter::make('statut_technicien')
                    ->label('Technicien')
                    ->options(['Freelance' => 'Freelance', 'Staff' => 'Staff']),
            ])
            ->recordActions([self::actionVoir()])
            ->emptyStateIcon(Heroicon::OutlinedWrenchScrewdriver)
            ->emptyStateHeading('Aucun rapport d\'installation')
            ->emptyStateDescription('Importez l\'export AppSheet avec : php artisan crm:importer-installations "<fichier>".');
    }

    /** Colonnes communes à la liste et à l'onglet de la fiche client. */
    public static function colonnes(bool $avecClient = true): array
    {
        return array_values(array_filter([
            $avecClient ? TextColumn::make('nom_client_installation')
                ->url(fn (Installation $record) => \App\Support\LiensCrm::client($record->id_client)) // ouvre la fiche client (si rattaché)
                ->label('Client')
                ->state(fn (Installation $record) => new HtmlString('<span class="gr-liste-client">'
                    .ClientInfolist::avatar($record->client ?? new Client(['nom_client' => self::nomClient($record)]), 'gr-ent-avatar gr-liste-avatar')
                    .'<span class="gr-liste-nom">'.e(self::nomClient($record)).'</span></span>'))
                ->html()
                ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                    ->where('num_cni', 'like', "%{$search}%")
                    ->orWhere('num_serie_pompe', 'like', '%'.str_replace(' ', '', $search).'%')
                    ->orWhere('assignee_a', 'like', "%{$search}%")
                    ->orWhere('localite', 'like', "%{$search}%")
                    ->orWhere('id_client', $search)
                    ->orWhereHas('client', fn (Builder $c) => $c->where('nom_client', 'like', '%'.Str::upper($search).'%')
                        ->orWhere('nom_client', 'like', "%{$search}%")
                        ->orWhere('prenoms_client', 'like', "%{$search}%")))) : null,
            // Une information par colonne
            $avecClient ? TextColumn::make('id_client')
                ->label('N° client')
                ->fontFamily('mono')
                ->size('xs')
                ->placeholder('Introuvable dans le CRM') : null,
            TextColumn::make('systeme_installe')
                ->label('Statut')
                ->state(fn (Installation $record) => match ($record->systeme_installe) {
                    1 => 'Installé',
                    0 => 'Non installé',
                    default => 'Non renseigné',
                })
                ->badge()
                ->icon(fn (Installation $record) => $record->systeme_installe === 1 ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedXCircle)
                ->color(fn (Installation $record) => match ($record->systeme_installe) {
                    1 => 'success',
                    0 => 'danger',
                    default => 'gray',
                }),
            TextColumn::make('motif_non_installation')
                ->label('Motif (non installé)')
                ->state(fn (Installation $record) => $record->systeme_installe === 1
                    ? null
                    : ($record->cause_non_installation ?: $record->raison_non_installation))
                ->limit(35)
                ->tooltip(fn (Installation $record) => $record->cause_non_installation ?: $record->raison_non_installation)
                ->placeholder('—'),
            TextColumn::make('date_installation')
                ->label('Date')
                ->date('d M Y')
                ->sortable()
                ->placeholder('—'),
            TextColumn::make('type_produit')
                ->label('Produit installé')
                ->formatStateUsing(fn (?string $state) => Str::limit((string) $state, 32))
                ->tooltip(fn (Installation $record) => $record->type_produit)
                ->badge()
                ->color('info')
                ->placeholder('—'),
            TextColumn::make('assignee_a')
                ->label('Technicien')
                ->placeholder('—'),
            TextColumn::make('statut_technicien')
                ->label('Statut technicien')
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('localite')
                ->label('Localité')
                ->placeholder('—'),
            TextColumn::make('ville')
                ->label('Ville')
                ->placeholder('—'),
            TextColumn::make('type_paiement')
                ->label('Paiement')
                ->badge()
                ->color(fn (?string $state) => $state === 'CASH' ? 'warning' : 'gray')
                ->placeholder('—'),
            TextColumn::make('num_serie_pompe')
                ->label('N° série pompe')
                ->copyable()
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    public static function nomClient(Installation $record): string
    {
        return $record->client?->nom_complet ?? ($record->donnees_origine['Nom et prenoms du client'] ?? 'Client inconnu');
    }

    /** Rapport complet dans un panneau latéral. */
    public static function actionVoir(): Action
    {
        return Action::make('voirRapport')
            ->label('Voir le rapport')
            ->icon(Heroicon::OutlinedEye)
            ->iconButton()
            ->modalHeading(fn (Installation $record) => 'Rapport d\'installation · '.self::nomClient($record))
            ->modalDescription(fn (Installation $record) => collect([
                $record->date_installation?->format('d/m/Y'),
                $record->assignee_a ? 'par '.$record->assignee_a : null,
                $record->systeme_installe === 1 ? 'Système installé' : 'Non installé',
            ])->filter()->implode(' · '))
            ->modalWidth('4xl')
            ->slideOver()
            ->modalContent(fn (Installation $record) => view('filament.installations.rapport', [
                'installation' => $record,
                'lienFiche' => $record->id_client ? ClientResource::getUrl('view', ['record' => $record->id_client]) : null,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListeInstallations::route('/'),
        ];
    }
}
