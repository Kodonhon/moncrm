<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historique : un SMS envoyé (ou simulé, ou en échec) à un numéro. */
class SmsMessage extends Model
{
    use \App\Models\Concerns\Journalise; // chaque changement va dans le journal du client

    protected static function booted(): void
    {
        // Périmètre de l'utilisateur (rôles et accès) : à travers le client (ex. « Son shop »)
        static::addGlobalScope(new \App\Models\Scopes\PerimetreAccesScope);
    }

    protected $table = 'sms_messages';

    protected $guarded = ['id'];

    public const STATUTS = [
        'en_attente' => 'En attente',
        'envoye' => 'Envoyé',
        'echec' => 'Échec',
        'simule' => 'Simulé (test)',
    ];

    protected function casts(): array
    {
        return ['envoye_le' => 'datetime'];
    }

    public function modele(): BelongsTo
    {
        return $this->belongsTo(SmsModele::class, 'sms_modele_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'id_client');
    }

    public function expediteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'envoye_par');
    }
}
