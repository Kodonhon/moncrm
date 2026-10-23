<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Formulaires\ConstructeurFormulaire;
use App\Models\Agent;
use App\Models\Client;
use App\Models\CreditScoring;
use App\Models\Kyc;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Ajout et modification du KYC d'un client, avec le formulaire configuré dans Paramètres > Formulaire KYC.
 */
class KycActions
{
    public static function ajouter(): Action
    {
        return Action::make('ajouterKyc')
            ->authorize(fn () => \App\Support\Acces::peut('kyc.creer'))
            ->label('Ajouter un KYC')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->visible(fn (Client $record) => ! $record->kyc)
            ->modalHeading(fn (Client $record) => 'KYC de '.$record->nom_complet)
            ->modalSubmitActionLabel('Enregistrer le KYC')
            ->modalWidth('4xl')
            ->slideOver()
            ->schema(fn (Client $record) => (new ConstructeurFormulaire('kyc', $record))->sections())
            ->fillForm(fn (Client $record) => array_merge(
                (new ConstructeurFormulaire('kyc'))->valeursParDefaut($record),
                // Reprise des informations déjà connues sur le client
                array_filter([
                    'type_piece_id' => $record->id_type_piece_id,
                    'email' => $record->email_client,
                    'entite' => $record->entite_id_client,
                    'id_agent' => $record->id_agent,
                ], fn ($valeur) => filled($valeur)),
            ))
            ->action(function (Client $record, array $data) {
                $record->kycs()->create($data + ['visibilite_kyc' => 1]);
                $record->unsetRelation('kyc');

                Notification::make()->title('KYC enregistré')->success()->send();
            });
    }

    public static function modifier(): Action
    {
        return Action::make('modifierKyc')
            ->authorize(fn () => \App\Support\Acces::peut('kyc.modifier'))
            ->label('Modifier le KYC')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(fn (Client $record) => (bool) $record->kyc)
            ->modalHeading(fn (Client $record) => 'KYC de '.$record->nom_complet)
            ->modalSubmitActionLabel('Enregistrer les modifications')
            ->modalWidth('4xl')
            ->slideOver()
            ->schema(fn (Client $record) => (new ConstructeurFormulaire('kyc', $record))->sections())
            ->fillForm(fn (Client $record) => array_merge(
                (new ConstructeurFormulaire('kyc'))->valeursRepriseClient($record),
                self::donneesFormulaire($record->kyc),
            ))
            ->action(function (Client $record, array $data) {
                $renvoye = (int) $record->kyc->statut_validation_kyc === Kyc::VALIDATION_A_CORRIGER;
                // KYC renvoyé par le crédit : l'enregistrer le renvoie au crédit
                $record->kyc->update($data + ($renvoye ? ['statut_validation_kyc' => Kyc::VALIDATION_A_VALIDER] : []));
                $record->unsetRelation('kyc');

                Notification::make()
                    ->title('KYC mis à jour')
                    ->body($renvoye ? 'Il repart au crédit pour validation.' : null)
                    ->success()
                    ->send();
            });
    }

    /**
     * Décision de l'agent crédit sur le KYC : validé, ou renvoyé à l'agent terrain avec un motif.
     * Proposée dès que le dossier est transmis (KYC + au moins un dimensionnement).
     */
    public static function validationCredit(): Action
    {
        return Action::make('validerKycCredit')
            ->authorize(fn () => \App\Support\Acces::peut('kyc.valider'))
            ->label('Valider le KYC')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->visible(fn (Client $record) => CreditScoring::tableDisponible()
                && $record->kyc
                && (int) $record->kyc->statut_validation_kyc === Kyc::VALIDATION_A_VALIDER
                && $record->dossierPretPourCredit())
            ->modalHeading(fn (Client $record) => 'Validation crédit du KYC · '.$record->nom_complet)
            ->modalDescription('Vérifiez la pièce, les photos et la personne à contacter (onglet KYC) avant de décider.')
            ->modalSubmitActionLabel('Enregistrer la décision')
            ->modalWidth('lg')
            ->schema([
                ToggleButtons::make('decision')
                    ->label('Décision')
                    ->options(['valide' => 'KYC validé', 'a_corriger' => 'À corriger'])
                    ->icons(['valide' => Heroicon::OutlinedCheckCircle, 'a_corriger' => Heroicon::OutlinedArrowUturnLeft])
                    ->colors(['valide' => 'success', 'a_corriger' => 'danger'])
                    ->default('valide')
                    ->inline()
                    ->required()
                    ->live(),
                Textarea::make('motif')
                    ->label('Ce que l\'agent terrain doit corriger')
                    ->rows(3)
                    ->required(fn (Get $get) => $get('decision') === 'a_corriger')
                    ->visible(fn (Get $get) => $get('decision') === 'a_corriger'),
                Select::make('id_agent')
                    ->label('Agent crédit')
                    ->options(fn () => Agent::optionsCredit())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Client $record, array $data, Action $action) {
                $valide = $data['decision'] === 'valide';

                // Âge du client : de 18 à 76 ans
                if ($valide && ! $record->kyc->ageValide()) {
                    Notification::make()
                        ->title('KYC non validable')
                        ->body($record->kyc->age() === null
                            ? 'La date de naissance n\'est pas renseignée.'
                            : 'Le client a '.$record->kyc->age().' ans : il doit avoir entre '.Kyc::AGE_MIN.' et '.Kyc::AGE_MAX.' ans. Renvoyez le KYC à corriger.')
                        ->danger()
                        ->persistent()
                        ->send();

                    $action->halt();
                }
                $record->kyc->decisionCredit($valide, (int) $data['id_agent'], $data['motif'] ?? null);
                $record->unsetRelation('kyc');

                Notification::make()
                    ->title($valide ? 'KYC validé' : 'KYC renvoyé à l\'agent terrain')
                    ->body($valide ? null : 'Une fois corrigé, il reviendra automatiquement « à valider ».')
                    ->color($valide ? 'success' : 'warning')
                    ->icon($valide ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedArrowUturnLeft)
                    ->send();
            });
    }

    /** Valeurs brutes (dates au format AAAA-MM-JJ) + réponses aux questions ajoutées. */
    private static function donneesFormulaire(Kyc $kyc): array
    {
        return array_merge($kyc->getAttributes(), [
            'donnees_formulaire' => $kyc->donnees_formulaire ?? [],
        ]);
    }
}
