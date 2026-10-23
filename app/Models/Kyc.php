<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KYC d'un client (table « kyc » reprise du système existant).
 * Un KYC « fait » est un KYC visible (visibilite_kyc = 1) ; les fiches vides d'origine sont à 0.
 */
class Kyc extends Model
{
    use \App\Models\Concerns\Journalise; // chaque changement va dans le journal du client

    protected $table = 'kyc';

    protected $primaryKey = 'id_kyc';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $attributes = [
        'visibilite_kyc' => 1,
    ];

    protected function casts(): array
    {
        return [
            'date_kyc' => 'date',
            'date_edit_doc' => 'date',
            'date_expir_doc' => 'date',
            'd_nais' => 'date',
            'date_validation_kyc' => 'datetime',
            'donnees_formulaire' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Périmètre de l'utilisateur connecté (rôles et accès) ; aucun filtre en ligne de commande
        static::addGlobalScope(new \App\Models\Scopes\PerimetreAccesScope);

        static::creating(function (Kyc $kyc) {
            if (blank($kyc->id_kyc)) {
                do {
                    $kyc->id_kyc = bin2hex(random_bytes(4));
                } while (static::whereKey($kyc->id_kyc)->exists());
            }
            $kyc->date_kyc ??= now()->toDateString();
        });

        // Compatibilité avec le système d'origine : le texte du lien de parenté suit la liste
        static::saving(function (Kyc $kyc) {
            if ($kyc->isDirty('id_lien_par') && $kyc->id_lien_par) {
                $kyc->lien_parente = LienPar::find($kyc->id_lien_par)?->lib_lien_par ?? $kyc->lien_parente;
            }
        });

        // KYC modifié après la décision du crédit (corrigé, ou validé puis changé) : il repasse « à valider »
        static::updating(function (Kyc $kyc) {
            $modifie = collect($kyc->getDirty())->keys()->diff(self::COLONNES_VALIDATION)->isNotEmpty();

            if ($modifie && (int) $kyc->getOriginal('statut_validation_kyc') !== self::VALIDATION_A_VALIDER
                && $kyc->getOriginal('statut_validation_kyc') !== null
                && ! $kyc->isDirty('statut_validation_kyc')) {
                $kyc->statut_validation_kyc = self::VALIDATION_A_VALIDER;
            }
        });
    }

    /* Validation du KYC par le crédit (colonnes ajoutées par database/sql/credit_scoring.sql) */

    public const VALIDATION_A_VALIDER = 0;

    public const VALIDATION_VALIDE = 1;

    public const VALIDATION_A_CORRIGER = 2;

    public const COLONNES_VALIDATION = ['statut_validation_kyc', 'motif_validation_kyc', 'date_validation_kyc', 'id_agent_validation_kyc'];

    /** @return array{etat: string, libelle: string, couleur: string} */
    public function etatValidation(): array
    {
        return match ((int) $this->statut_validation_kyc) {
            self::VALIDATION_VALIDE => ['etat' => 'valide', 'libelle' => 'Validé par le crédit', 'couleur' => 'success'],
            self::VALIDATION_A_CORRIGER => ['etat' => 'a_corriger', 'libelle' => 'À corriger', 'couleur' => 'danger'],
            default => ['etat' => 'a_valider', 'libelle' => 'À valider par le crédit', 'couleur' => 'warning'],
        };
    }

    public function estValideParLeCredit(): bool
    {
        return (int) $this->statut_validation_kyc === self::VALIDATION_VALIDE;
    }

    /** Décision de l'agent crédit sur le KYC (le motif est obligatoire pour un renvoi). */
    public function decisionCredit(bool $valide, int $idAgent, ?string $motif = null): void
    {
        $this->update([
            'statut_validation_kyc' => $valide ? self::VALIDATION_VALIDE : self::VALIDATION_A_CORRIGER,
            'motif_validation_kyc' => $valide ? null : $motif,
            'date_validation_kyc' => now(),
            'id_agent_validation_kyc' => $idAgent,
        ]);
    }

    /** Âge accepté pour un client (inclus) : de 18 à 76 ans. */
    public const AGE_MIN = 18;

    public const AGE_MAX = 76;

    /** Date de naissance la plus ancienne acceptée : le client a au plus 76 ans révolus. */
    public static function naissanceAuPlusTot(): \Illuminate\Support\Carbon
    {
        return now()->startOfDay()->subYears(self::AGE_MAX + 1)->addDay();
    }

    /** Date de naissance la plus récente acceptée : le client a au moins 18 ans. */
    public static function naissanceAuPlusTard(): \Illuminate\Support\Carbon
    {
        return now()->startOfDay()->subYears(self::AGE_MIN);
    }

    public function age(): ?int
    {
        return $this->d_nais ? (int) $this->d_nais->diffInYears(now()) : null;
    }

    /** Âge entre 18 et 76 ans (date de naissance renseignée). */
    public function ageValide(): bool
    {
        return ($age = $this->age()) !== null && $age >= self::AGE_MIN && $age <= self::AGE_MAX;
    }

    /** Nombre de jours avant l'expiration de la pièce à partir duquel on prévient. */
    public const ALERTE_EXPIRATION_JOURS = 30;

    /**
     * Validité de la pièce d'identité d'après sa date d'expiration.
     *
     * @return array{etat: string, libelle: string, couleur: string}
     */
    public function etatPiece(): array
    {
        $expiration = $this->date_expir_doc?->copy()->startOfDay();

        if (! $expiration) {
            return ['etat' => 'inconnue', 'libelle' => 'Expiration non renseignée', 'couleur' => 'gray'];
        }

        $jours = (int) now()->startOfDay()->diffInDays($expiration, false);

        return match (true) {
            $jours < 0 => ['etat' => 'expiree', 'libelle' => 'Pièce expirée depuis le '.$expiration->format('d/m/Y'), 'couleur' => 'danger'],
            $jours === 0 => ['etat' => 'bientot', 'libelle' => 'Pièce expire aujourd\'hui', 'couleur' => 'warning'],
            $jours <= self::ALERTE_EXPIRATION_JOURS => ['etat' => 'bientot', 'libelle' => "Pièce expire dans {$jours} jour".($jours > 1 ? 's' : ''), 'couleur' => 'warning'],
            default => ['etat' => 'valide', 'libelle' => 'Pièce valide jusqu\'au '.$expiration->format('d/m/Y'), 'couleur' => 'success'],
        };
    }

    /**
     * Valable pour un contrat : actif et pièce non expirée (date d'expiration vide = valable).
     * La validation par le crédit vient après le contrat : elle n'est pas exigée ici.
     */
    public function estValable(): bool
    {
        return (int) $this->visibilite_kyc === 1
            && ($this->date_expir_doc === null || $this->date_expir_doc->copy()->startOfDay()->gte(now()->startOfDay()));
    }

    public function agentValidation(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'id_agent_validation_kyc');
    }

    public function lienPar(): BelongsTo
    {
        return $this->belongsTo(LienPar::class, 'id_lien_par');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'id_client');
    }

    public function typeVente(): BelongsTo
    {
        return $this->belongsTo(TypeVente::class, 'id_type_vente');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'id_agent');
    }

    public function typePiece(): BelongsTo
    {
        return $this->belongsTo(TypePiece::class, 'type_piece_id');
    }
}
