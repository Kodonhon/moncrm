<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceClient extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_src_client';

    public const COLONNE_VISIBILITE = 'visibilite_src_client';

    protected $table = 'src_client';

    protected $primaryKey = 'id_src_client';

    public function canaux(): HasMany
    {
        return $this->hasMany(CanalVente::class, 'id_src_client');
    }
}
