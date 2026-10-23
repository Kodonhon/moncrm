<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Kit assigné par le shop manager à un client prêt pour l'installation : numéros de série du contrôleur,
 * de la pompe et des deux panneaux. Le client passe alors dans « Prêts pour l'installation » pour le technicien.
 * Table créée par database/sql/assignation_kit.sql.
 */
class AssignationKit extends Model
{
    use Concerns\Journalise; // chaque changement va dans le journal du client

    public const STATUTS = ['assigne' => 'Kit assigné', 'annule' => 'Assignation annulée'];

    protected $table = 'assignation_kit';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date_assignation' => 'date'];
    }

    protected static function booted(): void
    {
        // Périmètre de l'utilisateur (rôles et accès) : à travers le client (ex. « Son shop »)
        static::addGlobalScope(new Scopes\PerimetreAccesScope);
        static::saved(fn () => Cache::forget('kits-compteurs'));
    }

    public static function tableDisponible(): bool
    {
        return Cache::remember('table-assignation-kit-disponible', now()->addMinutes(10), fn () => Schema::hasTable('assignation_kit'));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'id_client');
    }

    public function technicien(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'id_agent_technicien');
    }
}
