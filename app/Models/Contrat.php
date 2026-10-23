<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Contrat : un TDH validé (évaluation du site) + le KYC valable du même client.
 * Un TDH ne donne qu'un seul contrat. Table créée par database/sql/contrat_regles.sql.
 */
class Contrat extends Model
{
    use \App\Models\Concerns\Journalise; // chaque changement va dans le journal du client

    public const STATUTS = ['actif' => 'Actif', 'resilie' => 'Résilié', 'termine' => 'Terminé'];

    protected $table = 'contrat';

    protected $primaryKey = 'id_contrat';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'num_contrat', 'id_client', 'id_eval', 'id_kyc', 'id_prod', 'id_type_vente',
        'montant', 'date_contrat', 'statut', 'id_agent', 'num_contrat_upya',
    ];

    /** Colonne num_contrat_upya créée (script database/sql/contrat_upya.sql) ? */
    public static function upyaDisponible(): bool
    {
        return Cache::remember('contrat-upya-disponible', now()->addMinutes(10),
            fn () => static::tableDisponible() && Schema::hasColumn('contrat', 'num_contrat_upya'));
    }

    protected $attributes = [
        'statut' => 'actif',
    ];

    protected function casts(): array
    {
        return [
            'date_contrat' => 'date',
            'montant' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Périmètre de l'utilisateur connecté (rôles et accès) ; aucun filtre en ligne de commande
        static::addGlobalScope(new \App\Models\Scopes\PerimetreAccesScope);

        // Nouveau n° UPYA : la file crédit en tient compte tout de suite
        static::saved(fn () => Cache::forget('clients-avec-deposit-complet'));

        static::creating(function (Contrat $contrat) {
            if (blank($contrat->id_contrat)) {
                do {
                    $contrat->id_contrat = bin2hex(random_bytes(4));
                } while (static::whereKey($contrat->id_contrat)->exists());
            }
            $contrat->date_contrat ??= now()->toDateString();
            $contrat->num_contrat ??= static::prochainNumero();
        });
    }

    /** CT-2026-000001, CT-2026-000002… (numérotation par année). */
    public static function prochainNumero(): string
    {
        $prefixe = 'CT-'.now()->format('Y').'-';
        $dernier = static::query()->where('num_contrat', 'like', $prefixe.'%')->max('num_contrat');

        return $prefixe.str_pad((string) ((int) substr((string) $dernier, strlen($prefixe)) + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * La table contrat est créée par un script SQL exécuté à la main : tant qu'il n'a pas été lancé,
     * les écrans des contrats restent masqués au lieu de provoquer une erreur.
     */
    public static function tableDisponible(): bool
    {
        return Cache::remember('table-contrat-disponible', now()->addMinutes(10), fn () => Schema::hasTable('contrat'));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'id_client');
    }

    public function evalSite(): BelongsTo
    {
        return $this->belongsTo(EvalSite::class, 'id_eval', 'id_eval_site');
    }

    public function kyc(): BelongsTo
    {
        return $this->belongsTo(Kyc::class, 'id_kyc');
    }
}
