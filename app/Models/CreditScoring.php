<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Credit scoring d'un client, fait par un agent crédit (table « credit_scoring » du système d'origine).
 * Un enregistrement par appel / tentative : le plus récent fait foi.
 * Table créée par database/sql/credit_scoring.sql.
 */
class CreditScoring extends Model
{
    use \App\Models\Concerns\Journalise; // chaque changement va dans le journal du client

    /** « Statut du CDS2 » : la décision du crédit ; 1 = Validé (d'après le formulaire AppSheet d'origine). */
    public const CHAMP_DECISION = 'q42_cs';

    public const DECISION_VALIDEE = '1';

    /** Codes de « Statut du CDS 2 » (libellés d'AppSheet, retrouvés par l'ID des scorings exportés). */
    public const DECISIONS = [
        '1' => 'Validé',
        '2' => 'Invalidé',
        '3' => 'Appel non répondu',
        '4' => 'En cours de traitement',
        '5' => 'En attente de mise à jour',
        '6' => 'En cours',
        '7' => 'En cours de traitement',
        '8' => 'Injoignable',
    ];

    /** Groupes de la file crédit, d'après la décision du dernier scoring. */
    public const DECISIONS_INJOIGNABLE = ['3', '8'];

    public const DECISIONS_MISE_A_JOUR = ['5'];

    public const DECISIONS_INVALIDE = ['2'];

    /** « Statut de l'appel » : 1 = Joint. */
    public const CHAMP_APPEL = 'q41_cs';

    public const CHAMP_COMMENTAIRE = 'q47_cs';

    protected $table = 'credit_scoring';

    protected $primaryKey = 'id_cs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $attributes = [
        'visibilite_cs' => 1,
    ];

    protected function casts(): array
    {
        return [
            'date_cs' => 'date',
            'donnees_formulaire' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Périmètre de l'utilisateur (rôles et accès) : à travers le client (ex. « Son shop »)
        static::addGlobalScope(new \App\Models\Scopes\PerimetreAccesScope);

        static::creating(function (CreditScoring $scoring) {
            if (blank($scoring->id_cs)) {
                do {
                    $scoring->id_cs = bin2hex(random_bytes(4));
                } while (static::whereKey($scoring->id_cs)->exists());
            }
            $scoring->date_cs ??= now()->toDateString();
            $scoring->heure_cs ??= now()->format('H:i:s');
        });

        // Dépôt initial (q11_cs) : plus demandé au client, repris du deposit complet de son contrat UPYA
        static::saving(function (CreditScoring $scoring) {
            if (blank($scoring->q11_cs) && filled($scoring->num_contrat_upya)
                && PaiementContrat::depositComplet($scoring->num_contrat_upya)) {
                $scoring->q11_cs = (string) (int) round((float) PaiementContrat::depositDe($scoring->num_contrat_upya)['mt_deposit']);
            }
        });
    }

    /**
     * Table et colonnes créées par un script SQL exécuté à la main : tant qu'il n'a pas été lancé,
     * la validation crédit reste masquée au lieu de provoquer une erreur.
     */
    public static function tableDisponible(): bool
    {
        return Cache::remember('table-credit-scoring-disponible', now()->addMinutes(10), fn () => Schema::hasTable('credit_scoring'));
    }

    public function estValide(): bool
    {
        return (string) $this->{self::CHAMP_DECISION} === self::DECISION_VALIDEE;
    }

    /** Libellé de la décision, d'après les choix configurés dans Paramètres. */
    public function libelleDecision(): ?string
    {
        return $this->libelleReponse(self::CHAMP_DECISION);
    }

    public function libelleReponse(string $cle): ?string
    {
        $champ = FormulaireChamp::pour('credit_scoring')->firstWhere('cle', $cle);
        $valeur = $this->{$cle};

        return $champ ? \App\Formulaires\ConstructeurFiche::formater($champ, $valeur) : (filled($valeur) ? (string) $valeur : null);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'id_client');
    }

    public function kyc(): BelongsTo
    {
        return $this->belongsTo(Kyc::class, 'id_kyc');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'id_agent');
    }

    /** Validation CX (préparation de l'installation) de ce dossier validé au scoring. */
    public function preparation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PreparationInstallation::class, 'id_cs');
    }
}
