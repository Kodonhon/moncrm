<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use EstUneReference;

    /** Nom complet « NOM Prénoms », colonne calculée par PostgreSQL (migration completer_table_agent). */
    public const COLONNE_LIBELLE = 'nom_complet_agent';

    public const COLONNE_VISIBILITE = 'visibilite_agent';

    protected $table = 'agent';

    protected $primaryKey = 'id_agent';

    /** Rôle (colonne role_agent ajoutée par database/sql/credit_scoring.sql). */
    public const ROLES = ['terrain' => 'Agent terrain', 'credit' => 'Agent crédit'];

    /** Codes du fichier d'origine (à confirmer : 1 = homme, 0 = femme). */
    public const GENRES = [1 => 'Homme', 0 => 'Femme'];

    protected function casts(): array
    {
        return [
            'date_entree_agent' => 'date',
            'date_elearning_agent' => 'date',
            'date_sortie_agent' => 'date',
        ];
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // EstUneReference vide $guarded : on protège la colonne calculée par la base, jamais écrite par Laravel.
        $this->guarded = ['nom_complet_agent'];

        // Colonne smallint : 1 / 0 plutôt que true / false (sinon PostgreSQL refuse « false » avec les requêtes émulées).
        $this->mergeCasts(['visibilite_agent' => \App\Casts\BooleenEntier::class]);
    }

    /**
     * Nom complet : colonne calculée par PostgreSQL si elle existe, sinon « NOM Prénoms » calculé ici
     * (tant que la colonne n'a pas été ajoutée à la base, les listes de choix restent utilisables).
     */
    public function getNomCompletAgentAttribute(?string $valeur): string
    {
        return $valeur ?? (trim(($this->attributes['nom_agent'] ?? '').' '.($this->attributes['prenoms_agent'] ?? '')) ?: 'Agent n° '.$this->getKey());
    }

    /** Agents crédit actifs [id => nom] ; s'il n'y en a encore aucun, tous les agents actifs. */
    public static function optionsCredit(int|string|null $valeurActuelle = null): array
    {
        return \App\Formulaires\ListesReference::options('agent_credit', $valeurActuelle);
    }

    public function siteShop(): BelongsTo
    {
        return $this->belongsTo(SiteShop::class, 'id_site_shop');
    }

    public function entite(): BelongsTo
    {
        return $this->belongsTo(Entite::class, 'id_entite');
    }

    public function superieur(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sup_agent_id');
    }

    public function subordonnes(): HasMany
    {
        return $this->hasMany(self::class, 'sup_agent_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'id_agent');
    }
}
