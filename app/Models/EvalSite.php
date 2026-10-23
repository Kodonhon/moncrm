<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Évaluation du site d'un client (table « eval_site » reprise du système existant).
 */
class EvalSite extends Model
{
    use \App\Models\Concerns\Journalise; // chaque changement va dans le journal du client

    public const STATUT_A_VALIDER = 0;

    public const STATUT_VALIDEE = 1;

    protected $table = 'eval_site';

    protected $primaryKey = 'id_eval_site';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date_eval_site' => 'date',
            'date_deb_assechement_tdh' => 'date',
            'date_fin_assechement_tdh' => 'date',
            'donnees_formulaire' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Périmètre de l'utilisateur connecté (rôles et accès) ; aucun filtre en ligne de commande
        static::addGlobalScope(new \App\Models\Scopes\PerimetreAccesScope);

        static::creating(function (EvalSite $evaluation) {
            if (blank($evaluation->id_eval_site)) {
                do {
                    $evaluation->id_eval_site = bin2hex(random_bytes(4));
                } while (static::whereKey($evaluation->id_eval_site)->exists());
            }
            $evaluation->date_eval_site ??= now()->toDateString();
            $evaluation->statut_eval_site ??= self::STATUT_A_VALIDER;
        });
    }

    public function estValidee(): bool
    {
        return (int) $this->statut_eval_site === self::STATUT_VALIDEE;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'id_client');
    }

    /** Un TDH ne donne qu'un seul contrat. */
    public function contrat(): HasOne
    {
        return $this->hasOne(Contrat::class, 'id_eval', 'id_eval_site');
    }

    /** TDH qui peuvent encore donner un contrat : validés et sans contrat. */
    public function scopeConvertibles(Builder $query): Builder
    {
        return $query->where('statut_eval_site', self::STATUT_VALIDEE)
            ->when(Contrat::tableDisponible(), fn (Builder $query) => $query->whereDoesntHave('contrat'));
    }

    /**
     * Code « Pompe proposée » du credit scoring (q12_cs : 11 = KOMDOGO, 12 = NIKLA),
     * déduit du produit choisi lors de l'évaluation du site (TDH).
     */
    public function getCodePompeCsAttribute(): ?string
    {
        $produit = mb_strtoupper((string) \App\Support\CacheReferences::libelle(Produit::class, $this->id_prod));

        return match (true) {
            str_contains($produit, 'KOMDOGO') => '11',
            str_contains($produit, 'NIKLA') => '12',
            default => null,
        };
    }
}
