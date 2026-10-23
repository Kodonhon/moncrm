<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'un client : chaque création, modification ou suppression sur le client et ses dossiers
 * (KYC, dimensionnements, contrats, scorings, préparation, SMS), avec l'auteur et le détail des champs changés.
 * Table créée par database/sql/journal_client.sql.
 */
class JournalClient extends Model
{
    protected $table = 'journal_client';

    public $timestamps = false;

    protected $guarded = [];

    /** Libellés des objets suivis */
    public const OBJETS = [
        'client' => 'Fiche client',
        'kyc' => 'KYC',
        'eval_site' => 'Dimensionnement',
        'contrat' => 'Contrat',
        'credit_scoring' => 'Credit scoring',
        'prep_inst' => 'Préparation installation',
        'installation' => 'Installation',
        'assignation_kit' => 'Kit assigné',
        'sms_messages' => 'SMS',
    ];

    public const ACTIONS = ['cree' => 'Création', 'modifie' => 'Modification', 'supprime' => 'Suppression'];

    protected function casts(): array
    {
        return [
            'changements' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function tableDisponible(): bool
    {
        return Cache::remember('table-journal-client-disponible', now()->addMinutes(10), fn () => Schema::hasTable('journal_client'));
    }
}
