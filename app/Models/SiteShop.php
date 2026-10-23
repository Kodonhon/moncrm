<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteShop extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_site_shop';

    public const COLONNE_VISIBILITE = 'visibilite_site_shop';

    protected $table = 'site_shop';

    protected $primaryKey = 'id_site_shop';

    public function localites(): HasMany
    {
        return $this->hasMany(Localite::class, 'id_site_shop');
    }
}
