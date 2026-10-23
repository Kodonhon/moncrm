<?php

namespace App\Models;

use App\Support\CacheReferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Client GREENO (table « client » reprise du système existant).
 */
class Client extends Model
{
    use \App\Models\Concerns\Journalise; // chaque changement va dans le journal du client

    protected $table = 'client';

    protected $primaryKey = 'id_client';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $attributes = [
        'visibilite_client' => 1,
    ];

    protected function casts(): array
    {
        return [
            'date_client' => 'date',
            'visibilite_client' => \App\Casts\BooleenEntier::class, // smallint 0/1 : écrit 1/0, lu true/false
            'donnees_formulaire' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Périmètre de l'utilisateur connecté (rôles et accès) ; aucun filtre en ligne de commande
        static::addGlobalScope(new \App\Models\Scopes\PerimetreAccesScope);

        static::creating(function (Client $client) {
            $client->id_client ??= static::genererIdentifiant();
            $client->date_client ??= now()->toDateString();
        });
    }

    /** Identifiant au même format que les clients récents : 8 caractères hexadécimaux. */
    public static function genererIdentifiant(): string
    {
        do {
            $identifiant = bin2hex(random_bytes(4));
        } while (static::whereKey($identifiant)->exists());

        return $identifiant;
    }

    public function canal(): BelongsTo
    {
        return $this->belongsTo(CanalVente::class, 'id_canal_vente');
    }

    public function typePiece(): BelongsTo
    {
        return $this->belongsTo(TypePiece::class, 'id_type_piece_id');
    }

    public function localite(): BelongsTo
    {
        return $this->belongsTo(Localite::class, 'id_localite_commune');
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class, 'genre_client', 'id_genre');
    }

    public function entite(): BelongsTo
    {
        return $this->belongsTo(Entite::class, 'entite_id_client');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SourceClient::class, 'id_src_client');
    }

    public function kycs(): HasMany
    {
        return $this->hasMany(Kyc::class, 'id_client');
    }

    /** KYC en vigueur : le plus récent des KYC visibles (les fiches vides d'origine sont masquées). */
    public function kyc(): HasOne
    {
        return $this->hasOne(Kyc::class, 'id_client')
            ->where('visibilite_kyc', 1)
            ->orderByDesc('date_kyc')
            ->orderByDesc('id_kyc');
    }

    public function evalSites(): HasMany
    {
        return $this->hasMany(EvalSite::class, 'id_client');
    }

    /** Évaluations de site / TDH du client (un par kit). */
    public function evaluations(): HasMany
    {
        return $this->evalSites();
    }

    public function contrats(): HasMany
    {
        return $this->hasMany(Contrat::class, 'id_client');
    }

    /** Credit scorings du client (un par appel / tentative). */
    public function creditScorings(): HasMany
    {
        return $this->hasMany(CreditScoring::class, 'id_client');
    }

    /** Rapports d'installation des techniciens. */
    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class, 'id_client');
    }

    /** Système installé : un rapport d'installation indique « installé = OUI ». */
    public function estInstalle(): bool
    {
        return Installation::tableDisponible() && $this->installations()->where('systeme_installe', 1)->exists();
    }

    /** Clients installés ($installe = true) ou pas encore installés (false). */
    public function scopeInstalle(Builder $query, bool $installe = true): Builder
    {
        return $installe
            ? $query->whereIn('id_client', Installation::clientsInstalles())
            : $query->whereNotIn('id_client', Installation::clientsInstalles());
    }

    /** Appels du CX pour la préparation de l'installation (table prep_inst). */
    public function prepInsts(): HasMany
    {
        return $this->hasMany(PrepInst::class, 'id_client');
    }

    /** Credit scoring en vigueur : le plus récent. */
    public function creditScoring(): HasOne
    {
        return $this->hasOne(CreditScoring::class, 'id_client')
            ->orderByDesc('date_cs')
            ->orderByDesc('heure_cs')
            ->orderByDesc('created_at');
    }

    /* ---------------------------------------------------------------------------------------------
     | Validation crédit : quand l'agent terrain a fait le KYC et au moins un dimensionnement,
     | un agent crédit valide le KYC (ou le renvoie à corriger) et fait le credit scoring.
     * ------------------------------------------------------------------------------------------- */

    public const CREDIT_ETATS = [
        'attente_terrain' => 'En attente du terrain',
        'attente_deposit' => 'En attente du deposit',
        'kyc_a_valider' => 'KYC à valider',
        'kyc_a_corriger' => 'KYC à corriger',
        'scoring_a_faire' => 'Scoring à faire',
        'valide' => 'Validé par le crédit',
    ];

    /** Le dossier est transmis au crédit : KYC fait et au moins un dimensionnement. */
    public function dossierPretPourCredit(): bool
    {
        return $this->kyc !== null && $this->evalSites()->exists();
    }

    public function etatCredit(): string
    {
        $scoringValide = fn () => $this->creditScorings()->where(CreditScoring::CHAMP_DECISION, CreditScoring::DECISION_VALIDEE)->exists();

        return match (true) {
            ! $this->dossierPretPourCredit() => 'attente_terrain',
            $this->kyc->estValideParLeCredit() && $scoringValide() => 'valide',
            ! $this->depositRecu() => 'attente_deposit',
            (int) $this->kyc->statut_validation_kyc === Kyc::VALIDATION_A_CORRIGER => 'kyc_a_corriger',
            ! $this->kyc->estValideParLeCredit() => 'kyc_a_valider',
            default => 'scoring_a_faire',
        };
    }

    /**
     * N° de contrat UPYA du client : ceux saisis dans ses contrats du CRM (le plus récent d'abord),
     * puis ceux de l'historique (scoring, préparation, installation).
     */
    public function numerosUpya(): array
    {
        $contrats = Contrat::upyaDisponible()
            ? $this->contrats()->whereNotNull('num_contrat_upya')->orderByDesc('date_contrat')->pluck('num_contrat_upya')->all()
            : [];
        $historique = array_keys(PaiementContrat::clientsParContrat(), (string) $this->id_client, true);

        return array_values(array_unique(array_map('strval', array_merge($contrats, $historique))));
    }

    /** N° de contrat UPYA à reprendre dans le credit scoring. */
    public function getNumContratUpyaActuelAttribute(): ?string
    {
        return $this->numerosUpya()[0] ?? null;
    }

    /** Montant du deposit complet du client (repris automatiquement dans le credit scoring). */
    public function getDepositPayeAttribute(): ?float
    {
        foreach ($this->numerosUpya() as $numero) {
            if (PaiementContrat::depositComplet($numero)) {
                return (float) PaiementContrat::depositDe($numero)['mt_deposit'];
            }
        }

        return null;
    }

    /** Deposit du client (le complet en priorité) : ['numero' => …, 'statut' => …, 'total_paye' => …, 'date_deposit' => …] ou null. */
    public function deposit(): ?array
    {
        $deposits = collect($this->numerosUpya())
            ->map(fn ($numero) => ($d = PaiementContrat::depositDe($numero)) ? $d + ['numero' => $numero] : null)
            ->filter();

        return $deposits->firstWhere('statut', 'full_deposit') ?? $deposits->first();
    }

    /** Un contrat UPYA du client a un deposit complet (full_deposit) dans la base des ventes. */
    public function aDepositComplet(): bool
    {
        return collect($this->numerosUpya())->contains(fn ($numero) => PaiementContrat::depositComplet($numero));
    }

    /** Le dossier peut passer au crédit côté paiement (règle inactive si la base des ventes n'est pas joignable). */
    public function depositRecu(): bool
    {
        return ! PaiementContrat::verificationActive() || $this->aDepositComplet();
    }

    /** Clients du CRM ayant un deposit complet sur l'un de leurs contrats UPYA. */
    public static function idsAvecDepositComplet(): array
    {
        // Utilisé par chaque onglet de la file crédit : calculé une fois, gardé 2 min (pas de cache en test : simulation)
        if (PaiementContrat::$simulation !== null) {
            return self::calculerIdsAvecDepositComplet();
        }

        return Cache::remember('clients-avec-deposit-complet', now()->addMinutes(2), fn () => self::calculerIdsAvecDepositComplet());
    }

    private static function calculerIdsAvecDepositComplet(): array
    {
        $numeros = PaiementContrat::clientsParContrat();
        if (Contrat::upyaDisponible()) {
            foreach (DB::table('contrat')->whereNotNull('num_contrat_upya')->get(['num_contrat_upya', 'id_client']) as $contrat) {
                $numeros[PaiementContrat::normaliser($contrat->num_contrat_upya)] = (string) $contrat->id_client;
            }
        }

        return collect($numeros)
            ->filter(fn ($client, $numero) => PaiementContrat::depositComplet((string) $numero))
            ->unique()->values()->all();
    }

    /** Pourquoi le credit scoring ne peut pas encore être fait (null : il peut l'être). */
    public function raisonScoringImpossible(): ?string
    {
        return match (true) {
            ! CreditScoring::tableDisponible() => 'La table credit_scoring n\'existe pas encore (script database/sql/credit_scoring.sql à exécuter).',
            ! $this->kyc => 'L\'agent terrain doit d\'abord faire le KYC.',
            ! $this->evalSites()->exists() => 'L\'agent terrain doit d\'abord faire au moins un dimensionnement.',
            ! $this->depositRecu() => 'Pas encore de deposit complet (full_deposit) sur le contrat UPYA du client.',
            default => null,
        };
    }

    /**
     * File de l'agent crédit. $etat : a_traiter (KYC à valider ou scoring à faire / en cours) | injoignable
     * | mise_a_jour | invalide (d'après la décision du dernier scoring) | valide | kyc_a_valider | scoring_a_faire
     * | kyc_a_corriger | attente_deposit.
     */
    public function scopeFileCredit(Builder $query, string $etat): Builder
    {
        $kyc = fn (?int $statut = null) => fn (QueryBuilder $q) => $q->select('id_client')->from('kyc')->where('visibilite_kyc', 1)
            ->when($statut !== null, fn (QueryBuilder $q) => $q->where('statut_validation_kyc', $statut));
        $scoringValide = fn (QueryBuilder $q) => $q->select('id_client')->from('credit_scoring')->whereNotNull('id_client')
            ->where(CreditScoring::CHAMP_DECISION, CreditScoring::DECISION_VALIDEE);

        // Système déjà installé : plus rien à faire pour le crédit
        $query->when(Installation::tableDisponible(), fn (Builder $q) => $q->installe(false));

        // Dossiers déjà scorés (Injoignable, En attente de mise à jour, Invalidé, Validé) : classés d'après le scoring,
        // historique AppSheet compris (ces clients n'ont souvent ni dimensionnement ni contrat dans le CRM)
        $derniere = fn (array $codes) => fn (QueryBuilder $q) => $q->select('cs.id_client')->from('credit_scoring as cs')
            ->whereNotNull('cs.id_client')
            ->whereIn('cs.'.CreditScoring::CHAMP_DECISION, $codes)
            ->whereNotExists(fn (QueryBuilder $plusRecent) => $plusRecent->selectRaw('1')->from('credit_scoring as cs2')
                ->whereColumn('cs2.id_client', 'cs.id_client')
                ->where(fn (QueryBuilder $w) => $w->whereColumn('cs2.date_cs', '>', 'cs.date_cs')
                    ->orWhere(fn (QueryBuilder $memeJour) => $memeJour->whereColumn('cs2.date_cs', 'cs.date_cs')
                        ->whereColumn('cs2.heure_cs', '>', 'cs.heure_cs'))));
        $nonValide = fn (Builder $q) => $q->whereNotIn('id_client', $scoringValide);

        switch ($etat) {
            case 'valide':
                return $query->whereIn('id_client', $scoringValide);
            case 'injoignable':
                return $nonValide($query)->whereIn('id_client', $derniere(CreditScoring::DECISIONS_INJOIGNABLE));
            case 'mise_a_jour':
                return $nonValide($query)->whereIn('id_client', $derniere(CreditScoring::DECISIONS_MISE_A_JOUR));
            case 'invalide':
                return $nonValide($query)->whereIn('id_client', $derniere(CreditScoring::DECISIONS_INVALIDE));
        }

        // Dossiers à traiter : transmis par le terrain (KYC + dimensionnement), puis deposit
        $query->whereIn('id_client', $kyc())
            ->whereIn('id_client', fn (QueryBuilder $q) => $q->select('id_client')->from('eval_site'));

        // Deposit : le dossier n'arrive au crédit qu'avec un deposit complet (full_deposit) sur un contrat UPYA
        $verification = PaiementContrat::verificationActive();
        if ($etat === 'attente_deposit') {
            return $verification
                ? $query->whereNotIn('id_client', self::idsAvecDepositComplet())
                    // Dossiers déjà validés par le crédit (historique) : plus en attente de rien
                    ->whereNot(fn (Builder $q) => $q->whereIn('id_client', $kyc(Kyc::VALIDATION_VALIDE))->whereIn('id_client', $scoringValide))
                : $query->whereRaw('1 = 0');
        }
        if ($verification) {
            $query->whereIn('id_client', self::idsAvecDepositComplet());
        }

        $dejaClasse = array_merge(CreditScoring::DECISIONS_INJOIGNABLE, CreditScoring::DECISIONS_MISE_A_JOUR, CreditScoring::DECISIONS_INVALIDE);

        return match ($etat) {
            'kyc_a_valider' => $query->whereIn('id_client', $kyc(Kyc::VALIDATION_A_VALIDER)),
            'kyc_a_corriger' => $query->whereIn('id_client', $kyc(Kyc::VALIDATION_A_CORRIGER)),
            'scoring_a_faire' => $query->whereIn('id_client', $kyc(Kyc::VALIDATION_VALIDE))->whereNotIn('id_client', $scoringValide),
            // À traiter : KYC à valider ou scoring à faire / en cours, hors dossiers classés (injoignable, mise à jour, invalidé)
            default => $query
                ->where(fn (Builder $q) => $q
                    ->whereIn('id_client', $kyc(Kyc::VALIDATION_A_VALIDER))
                    ->orWhere(fn (Builder $q) => $q->whereIn('id_client', $kyc(Kyc::VALIDATION_VALIDE))->whereNotIn('id_client', $scoringValide)))
                ->whereNotIn('id_client', $derniere($dejaClasse)),
        };
    }

    /** Un contrat exige un KYC valable et au moins un TDH validé sans contrat. */
    public function peutCreerContrat(): bool
    {
        return $this->raisonContratImpossible() === null;
    }

    /** Pourquoi un contrat ne peut pas être créé (null : il peut l'être). */
    public function raisonContratImpossible(): ?string
    {
        return match (true) {
            ! Contrat::tableDisponible() => 'La table des contrats n\'existe pas encore (script database/sql/contrat_regles.sql à exécuter).',
            ! $this->kyc => 'Ce client n\'a pas de KYC.',
            ! $this->kyc->estValable() => 'Le KYC du client n\'est pas valable ('.mb_strtolower($this->kyc->etatPiece()['libelle']).').',
            ! $this->evalSites()->convertibles()->exists() => 'Aucun dimensionnement validé sans contrat : validez un dimensionnement d\'abord.',
            default => null,
        };
    }

    /** Évaluation du site en vigueur : la plus récente. */
    public function evalSite(): HasOne
    {
        return $this->hasOne(EvalSite::class, 'id_client')
            ->orderByDesc('date_eval_site')
            ->orderByDesc('id_eval_site');
    }

    /** $etat : faite | a_faire | a_valider | validee */
    public function scopeEvaluationSite(Builder $query, string $etat): Builder
    {
        $evaluations = fn (QueryBuilder $q) => $q->select('id_client')->from('eval_site');

        return match ($etat) {
            'a_faire' => $query->whereNotIn('id_client', $evaluations),
            'a_valider' => $query->whereIn('id_client', fn (QueryBuilder $q) => $evaluations($q)->where(fn ($q) => $q->whereNull('statut_eval_site')->orWhere('statut_eval_site', EvalSite::STATUT_A_VALIDER))),
            'validee' => $query->whereIn('id_client', fn (QueryBuilder $q) => $evaluations($q)->where('statut_eval_site', EvalSite::STATUT_VALIDEE)),
            default => $query->whereIn('id_client', $evaluations),
        };
    }

    public function scopeAvecKyc(Builder $query, bool $fait = true): Builder
    {
        $avecKyc = fn (QueryBuilder $query) => $query->select('id_client')->from('kyc')->where('visibilite_kyc', 1);

        return $fait ? $query->whereIn('id_client', $avecKyc) : $query->whereNotIn('id_client', $avecKyc);
    }

    /** Clients dont la pièce du KYC est expirée ($etat = expiree) ou expire dans les 30 jours ($etat = bientot). */
    public function scopePieceKyc(Builder $query, string $etat): Builder
    {
        $aujourdhui = now()->toDateString();
        $limite = now()->addDays(Kyc::ALERTE_EXPIRATION_JOURS)->toDateString();

        return $query->whereIn('id_client', fn (QueryBuilder $kyc) => $kyc->select('id_client')->from('kyc')
            ->where('visibilite_kyc', 1)
            ->when($etat === 'expiree', fn (QueryBuilder $kyc) => $kyc->where('date_expir_doc', '<', $aujourdhui))
            ->when($etat === 'bientot', fn (QueryBuilder $kyc) => $kyc->whereBetween('date_expir_doc', [$aujourdhui, $limite])));
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'id_agent');
    }

    public function getNomCompletAttribute(): string
    {
        return trim("{$this->nom_client} {$this->prenoms_client}") ?: '(sans nom)';
    }

    /** Source saisie sur le client, sinon celle de son canal de vente (lue depuis le cache des références). */
    public function getSourceEffectiveAttribute(): ?SourceClient
    {
        return CacheReferences::trouver(SourceClient::class, $this->id_src_client ?? $this->ref(CanalVente::class, 'id_canal_vente')?->id_src_client);
    }

    /**
     * Élément de référence lié au client, servi depuis le cache (sans requête).
     * Ex. : $client->ref(Localite::class, 'id_localite_commune').
     *
     * @template T of Model
     *
     * @param  class-string<T>  $modele
     * @return T|null
     */
    public function ref(string $modele, string $colonne): ?Model
    {
        return CacheReferences::trouver($modele, $this->{$colonne});
    }

    /** « DALOA · SHOP DALOA » : ville et shop de la localité du client. */
    public function getVilleEtShopAttribute(): ?string
    {
        $localite = $this->ref(Localite::class, 'id_localite_commune');

        return collect([
            $localite?->id_ville ? CacheReferences::libelle(Ville::class, $localite->id_ville) : null,
            $localite?->id_site_shop ? CacheReferences::libelle(SiteShop::class, $localite->id_site_shop) : null,
        ])->filter()->implode(' · ') ?: null;
    }

    /** Filtre sur la source effective (saisie, ou déduite du canal de vente). */
    public function scopeDeSource(Builder $query, int $idSource): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('id_src_client', $idSource)
            ->orWhere(fn (Builder $query) => $query
                ->whereNull('id_src_client')
                ->whereIn('id_canal_vente', CacheReferences::tous(CanalVente::class)
                    ->filter(fn (CanalVente $canal) => (int) $canal->id_src_client === $idSource)->keys()->all())));
    }

    /** Filtre sur le site/shop, via la localité. */
    public function scopeDuShop(Builder $query, int $idShop): Builder
    {
        return $query->whereIn('id_localite_commune', CacheReferences::tous(Localite::class)
            ->filter(fn (Localite $localite) => (int) $localite->id_site_shop === $idShop)->keys()->all());
    }

    /** Filtre sur la ville, via la localité. */
    public function scopeDeLaVille(Builder $query, int $idVille): Builder
    {
        return $query->whereIn('id_localite_commune', CacheReferences::tous(Localite::class)
            ->filter(fn (Localite $localite) => (int) $localite->id_ville === $idVille)->keys()->all());
    }
}
