<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Contrats\CreerContrat;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Agent;
use App\Models\Client;
use App\Models\Contrat;
use App\Models\EvalSite;
use App\Models\PaiementContrat;
use App\Models\Produit;
use App\Models\TypeVente;
use App\Support\CacheReferences;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Contrats du client : un contrat par dimensionnement validé, avec le KYC valable du client.
 */
class ContratsRelationManager extends RelationManager
{
    protected static string $relationship = 'contrats';

    protected static ?string $title = 'Contrats';

    protected static ?string $modelLabel = 'contrat';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedDocumentCheck;

    /** Sur la fiche seulement, et une fois la table créée (script SQL). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return \App\Support\Acces::peut('contrats.voir') && $pageClass === ViewClient::class && Contrat::tableDisponible();
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->contrats()->count();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('num_contrat')
            // Un clic sur un contrat ouvre sa page (résumé, parcours, paiement)
            ->recordUrl(fn (Contrat $record) => \App\Filament\Resources\Contrats\ContratResource::getUrl('view', ['record' => $record]))
            ->defaultSort('date_contrat', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('num_contrat')->label('N° de contrat')->weight('semibold')->copyable(),
                TextColumn::make('num_contrat_upya')
                    ->label('Contrat UPYA')
                    ->copyable()
                    ->placeholder('—')
                    ->visible(fn () => Contrat::upyaDisponible()),
                TextColumn::make('deposit')
                    ->label('Deposit')
                    ->state(fn (Contrat $record) => filled($record->num_contrat_upya ?? null)
                        ? (PaiementContrat::STATUTS[PaiementContrat::depositDe($record->num_contrat_upya)['statut'] ?? ''] ?? 'Aucun deposit')
                        : null)
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'Dépôt complet' => 'success',
                        'Dépôt partiel' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->visible(fn () => Contrat::upyaDisponible() && PaiementContrat::verificationActive()),
                TextColumn::make('id_prod')
                    ->label('Produit')
                    ->formatStateUsing(fn (Contrat $record) => CacheReferences::libelle(Produit::class, $record->id_prod))
                    ->placeholder('—'),
                TextColumn::make('tdh')
                    ->label('TDH')
                    ->state(fn (Contrat $record) => filled($tdh = $record->evalSite?->tdh) ? number_format((float) $tdh, 2, ',', ' ').' m' : null)
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),
                TextColumn::make('montant')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state) => filled($state) ? number_format((float) $state, 0, ',', ' ').' FCFA' : null)
                    ->placeholder('—'),
                TextColumn::make('id_type_vente')
                    ->label('Vente')
                    ->formatStateUsing(fn (Contrat $record) => CacheReferences::libelle(TypeVente::class, $record->id_type_vente))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('date_contrat')->label('Signé le')->date('d M Y'),
                TextColumn::make('id_kyc')->label('KYC')->formatStateUsing(fn (?string $state) => $state ? 'n° '.$state : null)->color('gray'),
                TextColumn::make('statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Contrat::STATUTS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'actif' => 'success',
                        'resilie' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->headerActions([self::actionCreer()])
            ->emptyStateHeading('Aucun contrat')
            ->emptyStateDescription('Un contrat se crée à partir d\'un dimensionnement validé et du KYC valable du client.')
            ->emptyStateIcon(Heroicon::OutlinedDocumentPlus);
    }

    public static function actionCreer(): Action
    {
        $client = fn (Action $action): Client => $action->getLivewire()->getOwnerRecord();

        return Action::make('creerContrat')
            ->authorize(fn () => \App\Support\Acces::peut('contrats.creer'))
            ->label('Créer un contrat')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->disabled(fn (Action $action) => ! $client($action)->peutCreerContrat())
            ->tooltip(fn (Action $action) => $client($action)->raisonContratImpossible())
            ->modalHeading(fn (Action $action) => 'Nouveau contrat pour '.$client($action)->nom_complet)
            ->modalDescription('Un contrat = un dimensionnement validé + le KYC valable du client. Chaque dimensionnement ne donne qu\'un seul contrat.')
            ->modalSubmitActionLabel('Créer le contrat')
            ->modalWidth('3xl')
            ->slideOver()
            ->fillForm(fn (Action $action) => [
                'id_kyc' => $client($action)->kyc?->id_kyc,
                'kyc_affiche' => self::resumeKyc($client($action)),
                'id_type_vente' => $client($action)->kyc?->id_type_vente,
                'id_agent' => $client($action)->kyc?->id_agent ?? $client($action)->id_agent,
                'date_contrat' => now()->toDateString(),
                // Un seul dimensionnement convertible : présélectionné
                'id_eval' => ($convertibles = $client($action)->evalSites()->convertibles()->pluck('id_eval_site'))->count() === 1 ? $convertibles->first() : null,
                'id_prod' => $convertibles->count() === 1 ? EvalSite::find($convertibles->first())?->id_prod : null,
            ])
            ->schema(fn (Action $action) => [
                Section::make('Dimensionnement et KYC')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('id_eval')
                            ->label('Dimensionnement')
                            ->options(fn () => self::optionsTdh($client($action)))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (?string $state, Set $set) => $set('id_prod', $state ? EvalSite::find($state)?->id_prod : null))
                            ->helperText('Seuls les dimensionnements validés et sans contrat sont proposés.')
                            ->columnSpanFull(),
                        TextInput::make('kyc_affiche')
                            ->label('KYC du client')
                            ->disabled()
                            ->dehydrated(false)
                            ->prefixIcon(Heroicon::OutlinedIdentification)
                            ->columnSpanFull(),
                        Hidden::make('id_kyc'),
                    ]),
                Section::make('Conditions du contrat')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('id_prod')
                            ->label('Produit')
                            ->options(fn () => Produit::options())
                            ->searchable(),
                        ToggleButtons::make('id_type_vente')
                            ->label('Type de vente')
                            ->options(fn () => TypeVente::options())
                            ->inline(),
                        TextInput::make('montant')
                            ->label('Montant')
                            ->suffix('FCFA')
                            ->mask(RawJs::make('$money($input, \',\', \' \', 0)'))
                            ->stripCharacters(' ')
                            ->numeric()
                            ->minValue(0),
                        DatePicker::make('date_contrat')
                            ->label('Date du contrat')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required(),
                        Select::make('id_agent')
                            ->label('Agent')
                            ->options(fn () => Agent::options())
                            ->searchable(),
                        TextInput::make('num_contrat_upya')
                            ->label('N° de contrat UPYA')
                            ->visible(fn () => Contrat::upyaDisponible())
                            ->required()
                            ->regex('/^\d{7,9}$/')
                            ->validationMessages(['regex' => 'Le n° de contrat UPYA compte 7 à 9 chiffres, sans espace ni lettre.'])
                            ->prefixIcon(Heroicon::OutlinedBanknotes)
                            ->live(onBlur: true)
                            ->helperText(fn (?string $state) => self::etatDeposit($state))
                            ->columnSpanFull(),
                    ]),
            ])
            ->action(function (array $data, Action $action) use ($client) {
                try {
                    $contrat = app(CreerContrat::class)($client($action), $data);
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Contrat refusé')
                        ->body(collect($e->errors())->flatten()->implode(' '))
                        ->danger()
                        ->persistent()
                        ->send();

                    $action->halt();
                }

                Notification::make()->title('Contrat '.$contrat->num_contrat.' créé')->success()->send();
                $action->getLivewire()->dispatch(\App\Filament\Resources\Clients\Pages\ViewClient::EVENEMENT_MODIFIEE);
            });
    }

    /** Deposit du n° saisi, lu dans la base des ventes : le dossier passe au crédit avec un deposit complet. */
    private static function etatDeposit(?string $numero): string
    {
        if (blank($numero)) {
            return 'Numéro du contrat créé dans UPYA. Le dossier passe au crédit dès que son deposit est complet (full_deposit).';
        }
        if (! PaiementContrat::verificationActive()) {
            return 'Base des ventes injoignable : le deposit sera vérifié plus tard.';
        }

        return match (PaiementContrat::depositDe($numero)['statut'] ?? null) {
            'full_deposit' => '✔ Deposit complet trouvé : le dossier part au crédit dès la création du contrat.',
            'partial_deposit' => '◐ Deposit partiel : le dossier ira au crédit quand le deposit sera complet.',
            default => '✖ Aucun deposit pour ce n° pour l\'instant : vérifiez le numéro, ou attendez le paiement.',
        };
    }

    /** « Pompe TEVIA 1 CV · TDH 25 m · fait le 12/10/2026 » */
    private static function optionsTdh(Client $client): array
    {
        return $client->evalSites()->convertibles()->orderByDesc('date_eval_site')->get()
            ->mapWithKeys(fn (EvalSite $e) => [$e->id_eval_site => collect([
                CacheReferences::libelle(Produit::class, $e->id_prod) ?? 'Produit non renseigné',
                filled($e->tdh) ? 'TDH '.number_format((float) $e->tdh, 2, ',', ' ').' m' : null,
                $e->date_eval_site ? 'fait le '.$e->date_eval_site->format('d/m/Y') : null,
            ])->filter()->implode(' · ')])
            ->all();
    }

    private static function resumeKyc(Client $client): string
    {
        $kyc = $client->kyc;

        return $kyc
            ? 'n° '.$kyc->id_kyc.' · '.($kyc->num_piece_id ?: 'pièce non renseignée').' · '.$kyc->etatPiece()['libelle']
            : 'Aucun KYC';
    }
}
