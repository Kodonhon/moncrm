<?php

namespace App\Formulaires;

use App\Models\Agent;
use App\Models\Client;
use App\Models\CreditScoring;
use App\Models\Localite;
use App\Models\PrepInst;
use App\Models\Produit;
use App\Models\Ville;
use App\Support\CacheReferences;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Formulaire « Préparation installation » (appel du CX), repris du formulaire AppSheet d'origine.
 * Les informations du client, la pompe (TDH) et la source d'eau (credit scoring) sont reprises automatiquement.
 */
class FormulairePrepInst
{
    /** Dernier credit scoring validé du client (source d'eau, contrat UPYA). */
    public static function scoringValide(Client $client): ?CreditScoring
    {
        return $client->creditScorings()
            ->where(CreditScoring::CHAMP_DECISION, CreditScoring::DECISION_VALIDEE)
            ->orderByDesc('date_cs')
            ->orderByDesc('heure_cs')
            ->first();
    }

    /** Agents CX : fonctions 11 à 13 du fichier RH ; s'il n'y en a aucun, tous les agents actifs. */
    public static function agentsCx(?int $actuel = null): array
    {
        $cx = Agent::query()
            ->whereIn('id_fonction_agent', [11, 12, 13])
            ->where(fn ($q) => $q->where('visibilite_agent', 1)->when($actuel, fn ($q) => $q->orWhere('id_agent', $actuel)))
            ->orderBy('nom_complet_agent')
            ->pluck('nom_complet_agent', 'id_agent')
            ->all();

        return $cx ?: Agent::options($actuel);
    }

    /** Informations reprises du client, du TDH et du scoring (enregistrées avec l'appel). */
    public static function reprises(Client $client): array
    {
        $localite = $client->ref(Localite::class, 'id_localite_commune');
        $scoring = self::scoringValide($client);

        return [
            'id_client' => $client->id_client,
            'id_prospect' => $client->id_client,
            'id_cs' => $scoring?->id_cs,
            'nom_prenom' => $client->nom_complet,
            'cni_client' => $client->kyc?->num_piece_id,
            'contact1' => $client->contact1_client,
            'contact2' => $client->contact2_client,
            'ville' => $localite?->id_ville ? CacheReferences::libelle(Ville::class, $localite->id_ville) : null,
            'localite' => $localite?->lib_localite,
            'id_localite' => $client->id_localite_commune,
            'id_agent_vente' => $client->id_agent,
            'id_produit' => $client->evalSite?->id_prod,
            'num_contrat_upya' => $scoring?->num_contrat_upya ?: $client->kyc?->ref_client_upya,
        ];
    }

    public static function valeursParDefaut(Client $client): array
    {
        $scoring = self::scoringValide($client);
        $dernier = $client->prepInsts()->orderByDesc('date_prep')->orderByDesc('heure_prep')->first();

        return array_merge(self::reprises($client), [
            'date_prep' => now()->toDateString(),
            'id_agent_traitant' => $dernier?->id_agent_traitant,
            'id_src_eau' => $scoring?->id_src_eau ? (int) $scoring->id_src_eau : $dernier?->id_src_eau,
        ]);
    }

    public static function schema(): array
    {
        $joint = fn (Get $get) => in_array((int) $get('resul_prep_inst'), PrepInst::RESULTATS_JOINT, true);
        $ouiNon = fn (string $cle, string $libelle) => ToggleButtons::make($cle)->label($libelle)->boolean('Oui', 'Non')->inline()->grouped();

        return [
            Section::make('Appel')
                ->icon(Heroicon::OutlinedPhone)
                ->columns(2)
                ->schema([
                    DatePicker::make('date_prep')->label('Date')->required()->native(false)->displayFormat('d/m/Y'),
                    Select::make('id_agent_traitant')->label('Agent du CX')->options(fn ($state) => self::agentsCx($state ? (int) $state : null))->searchable()->required(),
                    ToggleButtons::make('resul_prep_inst')
                        ->label('Résultat de l\'appel')
                        ->options(PrepInst::RESULTATS)
                        ->colors([2 => 'success', 9 => 'danger', 7 => 'danger', 8 => 'danger', 12 => 'danger', 3 => 'info', 4 => 'danger', 5 => 'danger', 10 => 'danger'])
                        ->required()
                        ->live()
                        ->columnSpanFull(),
                ]),
            Section::make('Client (repris de la fiche)')
                ->icon(Heroicon::OutlinedUser)
                ->columns(3)
                ->collapsible()
                ->schema([
                    TextInput::make('nom_prenom')->label('Nom et prénoms')->disabled()->dehydrated(false),
                    TextInput::make('cni_client')->label('N° CNI')->disabled()->dehydrated(false),
                    TextInput::make('num_contrat_upya')->label('Contrat UPYA')->disabled()->dehydrated(false),
                    TextInput::make('contact1')->label('Contact 1')->disabled()->dehydrated(false),
                    TextInput::make('contact2')->label('Contact 2')->disabled()->dehydrated(false),
                    TextInput::make('localite')->label('Localité')->disabled()->dehydrated(false),
                ]),
            Section::make('Installation')
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->columns(2)
                ->visible($joint)
                ->schema([
                    Select::make('id_produit')->label('Type de pompe')
                        ->options(fn ($state) => Produit::options($state))
                        ->disabled()->dehydrated(false)
                        ->helperText('Repris de l\'évaluation du site (TDH).'),
                    Select::make('id_src_eau')->label('Type de source d\'eau')
                        ->options(PrepInst::SOURCES_EAU)
                        ->helperText('Repris du credit scoring ; corrigez si besoin.'),
                    $ouiNon('tdh_correct', 'Le TDH est-il correct ?'),
                    $ouiNon('compatibilite_pompe', 'Compatibilité de la colonne d\'eau avec la pompe'),
                    $ouiNon('client_reservoir', 'Le client a-t-il un réservoir ?')->live(),
                    $ouiNon('client_supp_reserv', 'Le client a-t-il un support pour son réservoir ?')
                        ->visible(fn (Get $get) => (bool) $get('client_reservoir')),
                    Select::make('si_pas_pret_raison')->label('Si pas prêt, raison')
                        ->options(array_combine(PrepInst::RAISONS_PAS_PRET, PrepInst::RAISONS_PAS_PRET))
                        ->required(fn (Get $get) => (int) $get('resul_prep_inst') === 9)
                        ->visible(fn (Get $get) => (int) $get('resul_prep_inst') === 9),
                ]),
            Textarea::make('commentaire_prepa_inst')->label('Commentaire de l\'agent')->rows(3)->required()->columnSpanFull(),
        ];
    }

    /** Enregistre l'appel : réponses du formulaire + informations reprises (jamais modifiables dans le formulaire). */
    public static function enregistrer(Client $client, array $data): PrepInst
    {
        foreach (['tdh_correct', 'client_reservoir', 'client_supp_reserv', 'compatibilite_pompe'] as $cle) {
            if (array_key_exists($cle, $data) && $data[$cle] !== null) {
                $data[$cle] = $data[$cle] ? 1 : 0;
            }
        }

        return PrepInst::create($data + self::reprises($client) + [
            'last_modified_by' => auth()->user()?->email,
        ]);
    }
}
