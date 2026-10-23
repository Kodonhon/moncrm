<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entite extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_entite';

    public const COLONNE_VISIBILITE = 'vis_entite';

    protected $table = 'entite';

    protected $primaryKey = 'id_entite';

    public function canaux(): HasMany
    {
        return $this->hasMany(CanalVente::class, 'id_entite');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'entite_id_client');
    }
}
