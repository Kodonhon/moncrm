<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Formulaires\ConstructeurFiche;
use App\Formulaires\ConstructeurFormulaire;
use App\Models\Agent;
use App\Models\Client;
use App\Models\EvalSite;
use App\Models\Produit;
use App\Models\TypeEvalSite;
use App\Models\UsageKit;
use App\Support\CacheReferences;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Dimensionnements du client : un client peut prendre plusieurs produits, chacun avec son propre dimensionnement
 * (et, pour le pompage solaire, son propre TDH). Le KYC du client est réutilisé tant que sa pièce n'est pas expirée.
 */
class EvalSitesRelationManager extends RelationManager
{
    protected static string $relationship = 'evalSites';

    protected static ?string $title = 'Dimensionnements';

    protected static ?string $modelLabel = 'dimensionnement';

    protected static ?string $pluralModelLabel = 'dimensionnements';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedHomeModern;

    /** Affiché sur la fiche (consultation) seulement, pas sur la page de modification du client. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return \App\Support\Acces::peut('evaluations.voir') && $pageClass === ViewClient::class;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->evalSites()->count();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id_eval_site')
            ->defaultSort('date_eval_site', 'desc')
            ->paginated(false)
            ->recordAction('voirEvalSite')
            ->columns([
                TextColumn::make('id_prod')
                    ->label('Produit')
                    ->formatStateUsing(fn (EvalSite $record) => CacheReferences::libelle(Produit::class, $record->id_prod))
                    ->placeholder('Produit non renseigné')
                    ->weight('medium'),
                TextColumn::make('id_type_eval_site')
                    ->label('Type')
                    ->formatStateUsing(fn (EvalSite $record) => CacheReferences::libelle(TypeEvalSite::class, $record->id_type_eval_site))
                    ->placeholder('—'),
                TextColumn::make('tdh')
                    ->label('TDH')
                    ->formatStateUsing(fn (?string $state) => filled($state) ? number_format((float) $state, 2, ',', ' ').' m' : null)
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),
                TextColumn::make('id_usg_kit')
                    ->label('Usage')
                    ->formatStateUsing(fn (EvalSite $record) => CacheReferences::libelle(UsageKit::class, $record->id_usg_kit))
                    ->placeholder('—'),
                TextColumn::make('date_eval_site')
                    ->label('Fait le')
                    ->date('d M Y')
                    ->placeholder('—'),
                TextColumn::make('id_agent')
                    ->label('Agent')
                    ->formatStateUsing(fn (EvalSite $record) => CacheReferences::libelle(Agent::class, $record->id_agent))
                    ->placeholder('—'),
                TextColumn::make('id_kyc')
                    ->label('KYC utilisé')
                    ->formatStateUsing(fn (?string $state) => $state ? 'n° '.$state : null)
                    ->placeholder('—')
                    ->color('gray'),
                TextColumn::make('contrat')
                    ->label('Contrat')
                    ->state(fn (EvalSite $record) => \App\Models\Contrat::tableDisponible() ? $record->contrat?->num_contrat : null)
                    ->badge()
                    ->color('success')
                    ->placeholder(fn (EvalSite $record) => $record->estValidee() ? 'À créer' : '—')
                    ->visible(fn () => \App\Models\Contrat::tableDisponible()),
                TextColumn::make('statut_eval_site')
                    ->label('Statut')
                    ->formatStateUsing(fn (EvalSite $record) => $record->estValidee() ? 'Validé' : 'À valider')
                    ->badge()
                    ->color(fn (EvalSite $record) => $record->estValidee() ? 'success' : 'warning'),
            ])
            ->headerActions([
                $this->actionAjouter(),
            ])
            ->recordActions([
                $this->actionValider(),
                ActionGroup::make([
                    $this->actionVoir(),
                    $this->actionModifier(),
                ]),
            ])
            ->emptyStateHeading('Aucun dimensionnement pour l\'instant')
            ->emptyStateDescription('Faites un dimensionnement pour chaque kit : visite du site, besoins du client et, pour le pompage solaire, calcul du TDH.')
            ->emptyStateIcon(Heroicon::OutlinedCube);
    }

    private function client(): Client
    {
        return $this->getOwnerRecord();
    }

    private function actionAjouter(): Action
    {
        return Action::make('ajouterEvalSite')
            ->authorize(fn () => \App\Support\Acces::peut('evaluations.creer'))
            ->label('Faire un dimensionnement')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(fn () => 'Dimensionnement pour '.$this->client()->nom_complet)
            ->modalDescription(fn () => self::messageKyc($this->client()))
            ->modalSubmitActionLabel('Enregistrer le dimensionnement')
            ->modalWidth('5xl')
            ->slideOver()
            ->schema(fn () => (new ConstructeurFormulaire('eval_site', $this->client()))->sections())
            ->fillForm(fn () => array_merge(
                (new ConstructeurFormulaire('eval_site'))->valeursParDefaut($this->client()),
                array_filter(['id_agent' => $this->client()->kyc?->id_agent ?? $this->client()->id_agent], fn ($valeur) => filled($valeur)),
            ))
            ->action(function (array $data) {
                $client = $this->client();
                $data = (new ConstructeurFormulaire('eval_site', $client))->appliquerCalculs($data);

                // Le KYC en vigueur est rattaché au produit (il reste valable tant que la pièce n'est pas expirée)
                $client->evalSites()->create($data + ['id_kyc' => $client->kyc?->id_kyc]);
                $client->unsetRelation('evalSite');

                Notification::make()->title('Dimensionnement enregistré')->success()->send();
                $this->dispatch(\App\Filament\Resources\Clients\Pages\ViewClient::EVENEMENT_MODIFIEE);
            });
    }

    private function actionModifier(): Action
    {
        return Action::make('modifierEvalSite')
            ->authorize(fn () => \App\Support\Acces::peut('evaluations.modifier'))
            ->label('Modifier le dimensionnement')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading(fn (EvalSite $record) => 'Dimensionnement · '.(CacheReferences::libelle(Produit::class, $record->id_prod) ?? 'produit'))
            ->modalSubmitActionLabel('Enregistrer les modifications')
            ->modalWidth('5xl')
            ->slideOver()
            ->schema(fn () => (new ConstructeurFormulaire('eval_site', $this->client()))->sections())
            ->fillForm(fn (EvalSite $record) => array_merge(
                (new ConstructeurFormulaire('eval_site'))->valeursRepriseClient($this->client()),
                $record->getAttributes(),
                ['donnees_formulaire' => $record->donnees_formulaire ?? []],
            ))
            ->action(function (EvalSite $record, array $data) {
                $record->update((new ConstructeurFormulaire('eval_site', $this->client()))->appliquerCalculs($data));
                $this->client()->unsetRelation('evalSite');

                Notification::make()->title('Dimensionnement mis à jour')->success()->send();
                $this->dispatch(\App\Filament\Resources\Clients\Pages\ViewClient::EVENEMENT_MODIFIEE);
            });
    }

    private function actionVoir(): Action
    {
        return Action::make('voirEvalSite')
            ->label('Voir le détail')
            ->icon(Heroicon::OutlinedEye)
            ->modalHeading(fn (EvalSite $record) => 'Dimensionnement · '.(CacheReferences::libelle(Produit::class, $record->id_prod) ?? 'produit'))
            ->modalDescription(fn (EvalSite $record) => ($record->date_eval_site ? 'Réalisé le '.$record->date_eval_site->format('d/m/Y') : 'Date non renseignée')
                .(($agent = CacheReferences::libelle(Agent::class, $record->id_agent)) ? ' par '.$agent : '')
                .' · '.($record->estValidee() ? 'Validé' : 'À valider'))
            ->modalWidth('5xl')
            ->slideOver()
            ->schema(fn () => (new ConstructeurFiche('eval_site'))->compacte(colonnes: 3, hauteurPhotos: 130)->sections())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer');
    }

    private function actionValider(): Action
    {
        return Action::make('validerEvalSite')
            ->authorize(fn () => \App\Support\Acces::peut('evaluations.valider'))
            ->label('Valider')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->button()
            ->size('sm')
            ->visible(fn (EvalSite $record) => ! $record->estValidee())
            ->requiresConfirmation()
            ->modalHeading('Valider ce dimensionnement ?')
            ->action(function (EvalSite $record) {
                $record->update(['statut_eval_site' => EvalSite::STATUT_VALIDEE]);
                $this->client()->unsetRelation('evalSite');

                Notification::make()->title('Dimensionnement validé')->success()->send();
                $this->dispatch(\App\Filament\Resources\Clients\Pages\ViewClient::EVENEMENT_MODIFIEE);
            });
    }

    /** Le KYC du client sert pour tous ses produits tant que la pièce n'est pas expirée. */
    public static function messageKyc(Client $client): HtmlString|string
    {
        $kyc = $client->kyc;

        if (! $kyc) {
            return 'Ce client n\'a pas encore de KYC : vous pouvez faire le dimensionnement, et ajouter le KYC ensuite (onglet KYC).';
        }

        $etat = $kyc->etatPiece();

        return new HtmlString(match ($etat['etat']) {
            'expiree' => '<strong style="color: var(--danger-600)">'.e($etat['libelle']).'</strong> : mettez à jour le KYC du client (onglet KYC) pour ce dimensionnement.',
            default => 'Le KYC n° '.e($kyc->id_kyc).' du client sera utilisé ('.e(mb_strtolower($etat['libelle'])).').',
        });
    }
}
