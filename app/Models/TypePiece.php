<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TypePiece extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_type_piece_id';

    public const COLONNE_VISIBILITE = 'visibilite_type_piece_id';

    protected $table = 'type_piece_id';

    protected $primaryKey = 'id_type_piece_id';

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'id_type_piece_id');
    }
}
