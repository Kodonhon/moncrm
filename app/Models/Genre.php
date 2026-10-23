<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Genre extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_genre';

    public const COLONNE_VISIBILITE = 'vis_genre';

    protected $table = 'genre';

    protected $primaryKey = 'id_genre';

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'genre_client');
    }
}
