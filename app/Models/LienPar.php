<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lien de parenté entre le client et sa personne à contacter (KYC). */
class LienPar extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_lien_par';

    public const COLONNE_VISIBILITE = 'visibilite_lien_par';

    protected $table = 'lien_par';

    protected $primaryKey = 'id_lien_par';

    public function kycs(): HasMany
    {
        return $this->hasMany(Kyc::class, 'id_lien_par');
    }
}
