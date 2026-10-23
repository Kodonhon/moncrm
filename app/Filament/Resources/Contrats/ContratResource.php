<?php

namespace App\Filament\Resources\Contrats;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Schemas\ClientInfolist;
use App\Filament\Resources\Contrats\Pages\ListeContrats;
use App\Models\Agent;
use App\Models\Client;
use App\Models\Contrat;
use App\Models\PaiementContrat;
use App\Models\Produit;
use App\Models\TypeVente;
use App\Support\Acces;
use App\Support\CacheReferences;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Ventes > Contrats : tous les contrats du CRM (un dimensionnement validé + le KYC du client),
 * avec leur n° UPYA et l'état du deposit. Un contrat se crée depuis la fiche du client.
 */
class ContratResource extends Resource
{
    protected static ?string $model = Contrat::class;

    protected static ?string $slug = 'contrats';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Contrats';

    protected static ?string $modelLabel = 'contrat';

    protected static ?string $pluralModelLabel = 'contrats';

    protected static ?int $navigationSort = 2;

    protected static string|\UnitEnum|null $navigationGroup = 'Ventes';

    public static function canViewAny(): bool
    {
        return Contrat::tableDisponible() && Acces::peut('contrats.voir');
    }

    public static function canCreate(): bool
    {
        return false; // depuis la fiche du client (« Créer un contrat »)
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'client:id_client,nom_client,prenoms_client,contact1_client',
            'kyc',
        ]);
    }

    /** Statuts du compte (comme « Account status » dans UPYA) : deposit lu dans la base des ventes. */
    public const STATUTS_COMPTE = [
        'complet' => 'Dépôt complet',
        'partiel' => 'Dépôt partiel',
        'aucun' => 'Aucun deposit',
        'sans_upya' => 'Sans n° UPYA',
        'resilie' => 'Résilié',
        'termine' => 'Terminé',
    ];

    /** N° UPYA normalisé côté SQL (chiffres seuls, sans zéros de tête), comparable à PaiementContrat::normaliser(). */
    private const NUMERO_NORMALISE = "ltrim(regexp_replace(coalesce(num_contrat_upya, ''), '[^0-9]', '', 'g'), '0')";

    /** Masque une valeur en gardant le début et la fin : 225******5849, CI*******21. */
    public static function masquer(?string $valeur, int $debut, int $fin): ?string
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '') {
            return null;
        }
        $longueur = mb_strlen($valeur);
        if ($longueur <= $debut + $fin) {
            return $valeur;
        }

        return mb_substr($valeur, 0, $debut).str_repeat('*', $longueur - $debut - $fin).mb_substr($valeur, -$fin);
    }

    /** Téléphone au format 225XXXXXXXXXX (10 chiffres ivoiriens précédés de 225). */
    public static function telephone(?string $numero): ?string
    {
        $chiffres = preg_replace('/\D/', '', (string) $numero);
        if ($chiffres === '') {
            return null;
        }

        return strlen($chiffres) === 10 ? '225'.$chiffres : $chiffres;
    }

    public static function referenceCompte(Contrat $record): ?string
    {
        return $record->kyc?->ref_client_upya ?: null;
    }

    public static function deposit(Contrat $record): ?array
    {
        return Contrat::upyaDisponible() && filled($record->num_contrat_upya)
            ? PaiementContrat::depositDe($record->num_contrat_upya)
            : null;
    }

    public static function statutCompte(Contrat $record): ?string
    {
        return match (true) {
            $record->statut === 'resilie' => 'resilie',
            $record->statut === 'termine' => 'termine',
            ! Contrat::upyaDisponible() || blank($record->num_contrat_upya) => 'sans_upya',
            ! PaiementContrat::verificationActive() => null,
            default => match (self::deposit($record)['statut'] ?? null) {
                'full_deposit' => 'complet',
                'partial_deposit' => 'partiel',
                default => 'aucun',
            },
        };
    }

    public static function dateDepotComplet(Contrat $record): ?string
    {
        $deposit = self::deposit($record);

        return ($deposit['statut'] ?? null) === 'full_deposit' && filled($deposit['date_deposit'] ?? null)
            ? \Illuminate\Support\Carbon::parse($deposit['date_deposit'])->format('d-m-Y')
            : null;
    }

    /** Plan de paiement : produit + type de vente (ex. « KOMDOGO 855 · Crédit »). */
    public static function planPaiement(Contrat $record): ?string
    {
        $produit = CacheReferences::libelle(Produit::class, $record->id_prod);
        $vente = CacheReferences::libelle(TypeVente::class, $record->id_type_vente);

        return collect([$produit, $vente])->filter()->implode(' · ') ?: null;
    }

    /** Filtre « Statut du compte » : les deposits sont dans une autre base, on filtre sur leurs n° UPYA. */
    private static function filtrerStatutCompte(Builder $query, ?string $statut): Builder
    {
        if (blank($statut)) {
            return $query;
        }
        if (in_array($statut, ['resilie', 'termine'], true)) {
            return $query->where('statut', $statut);
        }
        if (! Contrat::upyaDisponible()) {
            return $statut === 'sans_upya' ? $query : $query->whereRaw('false');
        }
        if ($statut === 'sans_upya') {
            return $query->whereNull('num_contrat_upya');
        }

        $deposits = PaiementContrat::parNumero() ?? [];
        $numeros = fn (string $code) => array_map('strval', array_keys(array_filter($deposits, fn ($d) => ($d['statut'] ?? null) === $code)));
        $tableau = fn (array $liste) => '{'.implode(',', array_filter($liste, fn ($n) => ctype_digit($n))).'}';

        $query->where(fn (Builder $q) => $q->whereNull('statut')->orWhereNotIn('statut', ['resilie', 'termine']))->whereNotNull('num_contrat_upya');

        return match ($statut) {
            'complet' => $query->whereRaw(self::NUMERO_NORMALISE.' = ANY(?::text[])', [$tableau($numeros('full_deposit'))]),
            'partiel' => $query->whereRaw(self::NUMERO_NORMALISE.' = ANY(?::text[])', [$tableau($numeros('partial_deposit'))]),
            'aucun' => $query->whereRaw('NOT ('.self::NUMERO_NORMALISE.' = ANY(?::text[]))', [$tableau(array_map('strval', array_keys($deposits)))]),
            default => $query,
        };
    }

    public static function table(Table $table): Table
    {
        $upya = Contrat::upyaDisponible();

        return $table
            // Derniers contrats en haut ; le même jour, le plus récemment créé d'abord
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('date_contrat')->orderByDesc('created_at'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->searchPlaceholder('Rechercher…')
            ->recordUrl(fn (Contrat $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('id_interne')
                    ->label('ID interne')
                    ->state(fn (Contrat $record) => ($upya ? $record->num_contrat_upya : null) ?: $record->num_contrat)
                    ->icon(Heroicon::OutlinedEye)
                    ->iconColor('primary')
                    ->color('gray')
                    ->sortable(query: fn (Builder $query, string $direction) => $upya
                        ? $query->orderByRaw('num_contrat_upya '.($direction === 'asc' ? 'ASC' : 'DESC').' NULLS LAST')
                        : $query->orderBy('num_contrat', $direction))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('num_contrat', 'like', "%{$search}%")
                        ->when($upya, fn (Builder $q) => $q->orWhere('num_contrat_upya', 'like', '%'.preg_replace('/\D/', '', $search).'%')))),
                TextColumn::make('nom_client')
                    ->label('Nom')
                    ->state(fn (Contrat $record) => $record->client ? mb_strtoupper($record->client->nom_complet) : null)
                    ->weight('medium')
                    ->color('gray')
                    ->url(fn (Contrat $record) => $record->client ? ClientResource::getUrl('view', ['record' => $record->client]) : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('client', fn (Builder $c) => $c
                        ->where('nom_client', 'like', '%'.mb_strtoupper($search).'%')
                        ->orWhere('prenoms_client', 'like', "%{$search}%")
                        ->when(preg_replace('/\D/', '', $search) !== '', fn (Builder $c) => $c->orWhere('contact1_client', 'like', '%'.preg_replace('/\D/', '', $search).'%'))))
                    ->placeholder('—'),
                TextColumn::make('telephone')
                    ->label('Téléphone')
                    ->state(fn (Contrat $record) => self::masquer(self::telephone($record->client?->contact1_client), 3, 4))
                    ->copyable()
                    ->copyableState(fn (Contrat $record) => self::telephone($record->client?->contact1_client))
                    ->copyMessage('Numéro copié')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->iconPosition(\Filament\Support\Enums\IconPosition::After)
                    ->iconColor('gray')
                    ->extraAttributes(['class' => 'gr-cellule-masquee'])
                    ->placeholder('—'),
                TextColumn::make('reference_compte')
                    ->label('Référence compte')
                    ->state(fn (Contrat $record) => self::masquer(self::referenceCompte($record), 2, 2))
                    ->copyable()
                    ->copyableState(fn (Contrat $record) => self::referenceCompte($record))
                    ->copyMessage('Référence copiée')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->iconPosition(\Filament\Support\Enums\IconPosition::After)
                    ->iconColor('gray')
                    ->placeholder('—'),
                TextColumn::make('statut_compte')
                    ->label('Statut du compte')
                    ->state(fn (Contrat $record) => self::STATUTS_COMPTE[self::statutCompte($record)] ?? null)
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'Dépôt complet' => 'success',
                        'Dépôt partiel' => 'warning',
                        'Résilié' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('date_depot_complet')
                    ->label('Date dépôt complet')
                    ->state(fn (Contrat $record) => self::dateDepotComplet($record))
                    ->placeholder('—')
                    ->visible($upya),
                TextColumn::make('id_type_vente')
                    ->label('Type de compte')
                    ->formatStateUsing(fn ($state) => CacheReferences::libelle(TypeVente::class, $state))
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('plan_paiement')
                    ->label('Plan de paiement')
                    ->state(fn (Contrat $record) => self::planPaiement($record))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('id_prod')
                    ->label('Produit')
                    ->formatStateUsing(fn ($state) => CacheReferences::libelle(Produit::class, $state))
                    ->sortable()
                    ->placeholder('—'),
                // Colonnes CRM, masquées par défaut (bouton « colonnes »)
                TextColumn::make('num_contrat')
                    ->label('N° CRM')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('montant')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state) => filled($state) ? number_format((float) $state, 0, ',', ' ').' F' : null)
                    ->alignEnd()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('date_contrat')
                    ->label('Date de vente')
                    ->date('d-m-Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id_agent')
                    ->label('Agent')
                    ->formatStateUsing(fn ($state) => CacheReferences::libelle(Agent::class, $state))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('id_prod')
                    ->label('Produit')
                    ->placeholder('Tous les produits')
                    ->options(fn () => Produit::tousLesLibelles())
                    ->searchable(),
                SelectFilter::make('statut_compte')
                    ->label('Statut du compte')
                    ->placeholder('Tous les statuts')
                    ->options(self::STATUTS_COMPTE)
                    ->query(fn (Builder $query, array $data) => self::filtrerStatutCompte($query, $data['value'] ?? null)),
                SelectFilter::make('id_type_vente')
                    ->label('Type de compte')
                    ->placeholder('Tous les types')
                    ->options(fn () => TypeVente::tousLesLibelles()),
                Filter::make('periode')
                    ->label('Période de vente')
                    ->schema([
                        DatePicker::make('du')->label('Date de vente : du')->native(false)->displayFormat('d/m/Y')->placeholder('Date de début'),
                        DatePicker::make('au')->label('au')->native(false)->displayFormat('d/m/Y')->placeholder('Date de fin'),
                    ])
                    ->columns(2)
                    ->columnSpan(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['du'] ?? null, fn (Builder $q, $du) => $q->whereDate('date_contrat', '>=', $du))
                        ->when($data['au'] ?? null, fn (Builder $q, $au) => $q->whereDate('date_contrat', '<=', $au)))
                    ->indicateUsing(fn (array $data) => collect([
                        filled($data['du'] ?? null) ? 'Du '.\Illuminate\Support\Carbon::parse($data['du'])->format('d/m/Y') : null,
                        filled($data['au'] ?? null) ? 'Au '.\Illuminate\Support\Carbon::parse($data['au'])->format('d/m/Y') : null,
                    ])->filter()->values()->all()),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(5)
            ->deferFilters(false)
            ->persistFiltersInSession()
            ->headerActions([
                \Filament\Actions\Action::make('actualiser')
                    ->label('Actualiser')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->outlined()
                    ->action(fn ($livewire) => method_exists($livewire, 'actualiser') ? $livewire->actualiser() : null),
            ])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading('Aucun contrat')
            ->emptyStateDescription('Un contrat se crée depuis la fiche du client, à partir d\'un dimensionnement validé et de son KYC.');
    }

    /** Ligne de l'export CSV (valeurs complètes, non masquées). */
    public static function ligneExport(Contrat $record): array
    {
        return [
            'ID interne' => $record->num_contrat_upya ?: $record->num_contrat,
            'N° CRM' => $record->num_contrat,
            'Nom' => $record->client ? mb_strtoupper($record->client->nom_complet) : '',
            'Téléphone' => self::telephone($record->client?->contact1_client),
            'Référence compte' => self::referenceCompte($record),
            'Statut du compte' => self::STATUTS_COMPTE[self::statutCompte($record)] ?? '',
            'Date dépôt complet' => self::dateDepotComplet($record),
            'Type de compte' => CacheReferences::libelle(TypeVente::class, $record->id_type_vente),
            'Plan de paiement' => self::planPaiement($record),
            'Produit' => CacheReferences::libelle(Produit::class, $record->id_prod),
            'Montant' => $record->montant,
            'Date de vente' => $record->date_contrat?->format('d/m/Y'),
            'Agent' => CacheReferences::libelle(Agent::class, $record->id_agent),
        ];
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return Acces::peut('contrats.voir');
    }

    public static function infolist(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return Schemas\ContratInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListeContrats::route('/'),
            'view' => Pages\ViewContrat::route('/{record}'),
        ];
    }
}
