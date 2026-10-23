<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Validation CX d'un dossier validé au credit scoring (préparation de l'installation).
 * Table créée par database/sql/preparation_installation.sql. Pas de ligne = « à valider ».
 */
class PreparationInstallation extends Model
{
    public const A_VALIDER = 0;

    public const VALIDE = 1;

    public const A_REVOIR = 2;

    public const STATUTS = [
        self::A_VALIDER => 'À valider par le CX',
        self::VALIDE => 'Validé CX — prêt pour l\'installation',
        self::A_REVOIR => 'À revoir',
    ];

    protected $table = 'preparation_installation';

    protected $primaryKey = 'id_prep_inst';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'statut_cx' => 'integer',
            'date_validation_cx' => 'datetime',
        ];
    }

    public static function tableDisponible(): bool
    {
        return Cache::remember('table-preparation-installation-disponible', now()->addMinutes(10),
            fn () => Schema::hasTable('preparation_installation') && Schema::hasTable('credit_scoring'));
    }

    public function creditScoring(): BelongsTo
    {
        return $this->belongsTo(CreditScoring::class, 'id_cs');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'id_client');
    }

    public function agentCx(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'id_agent_cx');
    }
}
