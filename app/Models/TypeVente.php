<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TypeVente extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_type_vente';

    public const COLONNE_VISIBILITE = 'visibilite_type_vente';

    protected $table = 'type_vente';

    protected $primaryKey = 'id_type_vente';

    public function kycs(): HasMany
    {
        return $this->hasMany(Kyc::class, 'id_type_vente');
    }
}
