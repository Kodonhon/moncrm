<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Formulaires\ConstructeurFiche;
use App\Formulaires\ConstructeurFormulaire;
use App\Models\Agent;
use App\Models\Client;
use App\Models\CreditScoring;
use App\Support\CacheReferences;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Credit scorings du client, faits par l'agent crédit une fois le dossier transmis
 * (KYC + au moins un dimensionnement). Un enregistrement par appel : le plus récent fait foi.
 */
class CreditScoringsRelationManager extends RelationManager
{
    protected static string $relationship = 'creditScorings';

    protected static ?string $title = 'Credit scoring';

    protected static ?string $modelLabel = 'credit scoring';

    protected static ?string $pluralModelLabel = 'credit scorings';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedScale;

    /** Sur la fiche seulement, et une fois la table créée (script SQL). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return \App\Support\Acces::peut('credit_scoring.voir') && $pageClass === ViewClient::class && CreditScoring::tableDisponible();
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->creditScorings()->count();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id_cs')
            ->defaultSort('date_cs', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->orderByDesc('heure_cs'))
            ->paginated([10, 25, 'all'])
            ->recordAction('voirScoring')
            ->columns([
                TextColumn::make('date_cs')
                    ->label('Date')
                    ->date('d M Y')
                    ->placeholder('—'),
                TextColumn::make('heure_cs')
                    ->label('Heure')
                    ->formatStateUsing(fn ($state) => $state ? substr((string) $state, 0, 5) : null)
                    ->placeholder('—'),
                TextColumn::make('id_agent')
                    ->label('Agent crédit')
                    ->formatStateUsing(fn (CreditScoring $record) => CacheReferences::libelle(Agent::class, $record->id_agent))
                    ->placeholder('—'),
                TextColumn::make(CreditScoring::CHAMP_DECISION)
                    ->label('Statut du CDS2')
                    ->state(fn (CreditScoring $record) => $record->libelleDecision())
                    ->badge()
                    ->color(fn (CreditScoring $record) => $record->estValide() ? 'success' : 'warning')
                    ->placeholder('—'),
                TextColumn::make(CreditScoring::CHAMP_APPEL)
                    ->label('Appel')
                    ->state(fn (CreditScoring $record) => $record->libelleReponse(CreditScoring::CHAMP_APPEL))
                    ->placeholder('—'),
                TextColumn::make(CreditScoring::CHAMP_COMMENTAIRE)
                    ->label('Commentaire')
                    ->limit(70)
                    ->tooltip(fn (CreditScoring $record) => $record->{CreditScoring::CHAMP_COMMENTAIRE})
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->headerActions([
                $this->actionAjouter(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->actionVoir(),
                    $this->actionModifier(),
                ]),
            ])
            ->emptyStateHeading('Aucun credit scoring')
            ->emptyStateDescription(fn () => $this->client()->raisonScoringImpossible()
                ?? 'Le dossier est transmis au crédit : validez le KYC et faites le credit scoring.')
            ->emptyStateIcon(Heroicon::OutlinedScale);
    }

    private function client(): Client
    {
        return $this->getOwnerRecord();
    }

    private function actionAjouter(): Action
    {
        return Action::make('ajouterScoring')
            ->authorize(fn () => \App\Support\Acces::peut('credit_scoring.creer'))
            ->label('Faire le credit scoring')
            ->icon(Heroicon::OutlinedPlus)
            ->disabled(fn () => $this->client()->raisonScoringImpossible() !== null)
            ->tooltip(fn () => $this->client()->raisonScoringImpossible())
            ->modalHeading(fn () => 'Credit scoring · '.$this->client()->nom_complet)
            ->modalDescription(fn () => $this->resumeKyc())
            ->modalSubmitActionLabel('Enregistrer le scoring')
            ->modalWidth('5xl')
            ->slideOver()
            ->schema(fn () => (new ConstructeurFormulaire('credit_scoring', $this->client()))->sections())
            ->fillForm(fn () => array_merge(
                (new ConstructeurFormulaire('credit_scoring'))->valeursParDefaut($this->client()),
                // Agent crédit du scoring précédent, s'il y en a un
                array_filter(['id_agent' => $this->client()->creditScoring?->id_agent], fn ($valeur) => filled($valeur)),
            ))
            ->action(function (array $data) {
                $client = $this->client();
                $data = (new ConstructeurFormulaire('credit_scoring', $client))->appliquerCalculs($data);

                $scoring = $client->creditScorings()->create($data + [
                    'id_kyc' => $client->kyc?->id_kyc,
                    'id_prospect' => $client->id_client,
                ]);
                $client->unsetRelation('creditScoring');
                $this->dispatch(\App\Filament\Resources\Clients\Pages\ViewClient::EVENEMENT_MODIFIEE);

                Notification::make()
                    ->title('Credit scoring enregistré')
                    ->body($scoring->estValide() ? 'Dossier validé par le crédit.' : null)
                    ->success()
                    ->send();
            });
    }

    private function actionModifier(): Action
    {
        return Action::make('modifierScoring')
            ->authorize(fn () => \App\Support\Acces::peut('credit_scoring.modifier'))
            ->label('Modifier')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading(fn (CreditScoring $record) => 'Credit scoring du '.($record->date_cs?->format('d/m/Y') ?? '—'))
            ->modalSubmitActionLabel('Enregistrer les modifications')
            ->modalWidth('5xl')
            ->slideOver()
            ->schema(fn () => (new ConstructeurFormulaire('credit_scoring', $this->client()))->sections())
            ->fillForm(fn (CreditScoring $record) => array_merge(
                (new ConstructeurFormulaire('credit_scoring'))->valeursRepriseClient($this->client()),
                $record->getAttributes(),
                ['donnees_formulaire' => $record->donnees_formulaire ?? []],
            ))
            ->action(function (CreditScoring $record, array $data) {
                $record->update((new ConstructeurFormulaire('credit_scoring', $this->client()))->appliquerCalculs($data));
                $this->client()->unsetRelation('creditScoring');

                Notification::make()->title('Credit scoring mis à jour')->success()->send();
                $this->dispatch(\App\Filament\Resources\Clients\Pages\ViewClient::EVENEMENT_MODIFIEE);
            });
    }

    private function actionVoir(): Action
    {
        return Action::make('voirScoring')
            ->label('Voir le détail')
            ->icon(Heroicon::OutlinedEye)
            ->modalHeading(fn (CreditScoring $record) => 'Credit scoring du '.($record->date_cs?->format('d/m/Y') ?? '—'))
            ->modalDescription(fn (CreditScoring $record) => collect([
                ($agent = CacheReferences::libelle(Agent::class, $record->id_agent)) ? 'Par '.$agent : null,
                $record->libelleDecision(),
            ])->filter()->implode(' · '))
            ->modalWidth('5xl')
            ->slideOver()
            ->schema(fn () => (new ConstructeurFiche('credit_scoring'))->compacte(colonnes: 3)->sections())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer');
    }

    private function resumeKyc(): string
    {
        $kyc = $this->client()->kyc;

        return $kyc
            ? 'KYC n° '.$kyc->id_kyc.' · '.($kyc->num_piece_id ?: 'pièce non renseignée').' · '.$kyc->etatValidation()['libelle']
            : 'Aucun KYC';
    }
}
