<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Localite extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_localite';

    public const COLONNE_VISIBILITE = 'visibilite_localite_commune';

    protected $table = 'localite_commune';

    protected $primaryKey = 'id_localite_commune';

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class, 'id_ville');
    }

    public function siteShop(): BelongsTo
    {
        return $this->belongsTo(SiteShop::class, 'id_site_shop');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'id_localite_commune');
    }
}
