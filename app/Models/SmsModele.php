<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Modèle de SMS. Seuls les modèles approuvés et actifs peuvent servir à envoyer des messages. */
class SmsModele extends Model
{
    protected $table = 'sms_modeles';

    protected $guarded = ['id'];

    public const CATEGORIES = [
        'facturation' => 'Facturation',
        'onboarding' => 'Onboarding',
        'rappel' => 'Rappel d\'échéance',
        'relance' => 'Relance impayé',
        'alerte_service' => 'Alerte service',
        'installation' => 'Installation',
        'marketing' => 'Marketing',
    ];

    public const APPROBATIONS = [
        'brouillon' => 'Brouillon',
        'en_attente' => 'En attente',
        'approuve' => 'Approuvé',
        'rejete' => 'Rejeté',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'approuve_le' => 'datetime'];
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function approbateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class, 'sms_modele_id');
    }

    public function scopeUtilisables(Builder $query): Builder
    {
        return $query->where('approbation', 'approuve')->where('actif', true);
    }

    public function utilisable(): bool
    {
        return $this->approbation === 'approuve' && $this->actif;
    }

    /** Variables utilisées dans le contenu : {nom}, {num_contrat}… */
    public function variables(): array
    {
        preg_match_all('/\{([a-z_]+)\}/', (string) $this->contenu, $m);

        return array_values(array_unique($m[1]));
    }
}
