<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ville extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_ville';

    public const COLONNE_VISIBILITE = 'visibilite_ville';

    protected $table = 'ville';

    protected $primaryKey = 'id_ville';

    public function localites(): HasMany
    {
        return $this->hasMany(Localite::class, 'id_ville');
    }
}
