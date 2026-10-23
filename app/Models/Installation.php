<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Rapport d'installation d'un technicien (export AppSheet « ta_installation »).
 * Un client dont un rapport indique « système installé » sort des listes en attente d'action.
 * Table créée par database/sql/installation.sql.
 */
class Installation extends Model
{
    use \App\Models\Concerns\Journalise; // chaque changement va dans le journal du client

    protected static function booted(): void
    {
        // Périmètre de l'utilisateur (rôles et accès) : à travers le client (ex. « Son shop »)
        static::addGlobalScope(new \App\Models\Scopes\PerimetreAccesScope);
    }

    protected $table = 'installation';

    protected $primaryKey = 'id_installation';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date_installation' => 'date',
            'systeme_installe' => 'integer',
            'kit_recu' => 'integer',
            'donnees_origine' => 'array',
        ];
    }

    public static function tableDisponible(): bool
    {
        return Cache::remember('table-installation-disponible', now()->addMinutes(10), fn () => Schema::hasTable('installation'));
    }

    /** Sous-requête : clients dont le système est installé (à utiliser dans un whereIn / whereNotIn). */
    public static function clientsInstalles(): \Closure
    {
        return fn (QueryBuilder $q) => $q->select('id_client')->from('installation')
            ->whereNotNull('id_client')->where('systeme_installe', 1);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'id_client');
    }
}
