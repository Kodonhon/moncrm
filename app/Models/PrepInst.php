<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Appel du CX pour la préparation de l'installation (table « prep_inst » du système d'origine).
 * Un enregistrement par appel ; le plus récent donne l'état du dossier.
 * Table créée par database/sql/prep_inst.sql.
 */
class PrepInst extends Model
{
    use \App\Models\Concerns\Journalise; // chaque changement va dans le journal du client

    /** Résultat de l'appel (resul_prep_inst), codes du formulaire AppSheet d'origine. */
    public const RESULTATS = [
        2 => 'Prêt pour l\'installation',
        9 => 'Pas prêt pour l\'installation',
        1 => 'Injoignable',
        6 => 'Ne décroche pas',
        11 => 'Parent proche joint',
        7 => 'Colonne d\'eau incompatible',
        8 => 'Volume d\'eau insuffisant',
        12 => 'Pas de source d\'eau',
        3 => 'Déjà installé',
        4 => 'Souhaite résilier le contrat',
        5 => 'Contrat déjà résilié',
        10 => 'Souhaite annuler sa commande',
    ];

    /** Résultats où le client a été joint : les questions techniques sont posées. */
    public const RESULTATS_JOINT = [2, 9, 7, 8, 12];

    public const PRET = 2;

    /** État du dossier d'après le dernier appel : [libellé, couleur Filament, codes]. */
    public const ETATS = [
        'a_appeler' => ['À appeler', 'gray', []],
        'a_rappeler' => ['À rappeler', 'warning', [1, 6, 11]],
        'pas_pret' => ['Pas prêt', 'danger', [9, 7, 8, 12]],
        'pret' => ['Prêt pour l\'installation', 'success', [2]],
        'installe' => ['Déjà installé', 'info', [3]],
        'resiliation' => ['Résiliation / annulation', 'danger', [4, 5, 10]],
    ];

    /** Type de source d'eau (id_src_eau), mêmes codes que le credit scoring. */
    public const SOURCES_EAU = [
        3 => 'Puits', 1 => 'Forage', 8 => 'Rivière', 6 => 'Fleuve', 7 => 'Lac', 5 => 'Bas fond', 4 => 'Barrage', 2 => 'Lac / Rivière / Fleuve',
    ];

    public const RAISONS_PAS_PRET = [
        'Indisponibilité',
        'Souhait d’avoir le tank et le support',
        'Aménagement de la source d’eau',
        'Construction de la source d’eau',
        'Aménagement du site',
        'Problèmes financiers',
        'Autres',
    ];

    protected $table = 'prep_inst';

    protected $primaryKey = 'id_prep';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $attributes = [
        'visibilite' => 1,
        'id_type_req' => 1,
    ];

    protected function casts(): array
    {
        return [
            'date_prep' => 'date',
            'resul_prep_inst' => 'integer',
            'id_produit' => 'integer',
            'id_src_eau' => 'integer',
            'id_agent_traitant' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Périmètre de l'utilisateur (rôles et accès) : à travers le client (ex. « Son shop »)
        static::addGlobalScope(new \App\Models\Scopes\PerimetreAccesScope);

        static::creating(function (PrepInst $appel) {
            if (blank($appel->id_prep)) {
                do {
                    $appel->id_prep = bin2hex(random_bytes(4));
                } while (static::whereKey($appel->id_prep)->exists());
            }
            $appel->date_prep ??= now()->toDateString();
            $appel->heure_prep ??= now()->format('H:i:s');
            // Statut d'origine : 1 = prêt pour l'installation
            $appel->statut_prepa_inst = (int) $appel->resul_prep_inst === self::PRET ? 1 : 0;
        });
    }

    public static function tableDisponible(): bool
    {
        return Cache::remember('table-prep-inst-disponible', now()->addMinutes(10),
            fn () => Schema::hasTable('prep_inst') && Schema::hasTable('credit_scoring'));
    }

    /** Clé d'état (voir ETATS) d'après un code de résultat ; null = jamais appelé. */
    public static function etat(?int $resultat): string
    {
        if ($resultat === null) {
            return 'a_appeler';
        }

        foreach (self::ETATS as $cle => [, , $codes]) {
            if (in_array($resultat, $codes, true)) {
                return $cle;
            }
        }

        return 'a_rappeler';
    }

    public function libelleResultat(): ?string
    {
        return self::RESULTATS[$this->resul_prep_inst] ?? ($this->resul_prep_inst ? 'Code '.$this->resul_prep_inst : null);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'id_client');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'id_agent_traitant');
    }
}
