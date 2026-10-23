<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;

/** Source d'énergie actuelle du client. */
class SourceEnergie extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_src_energie';

    public const COLONNE_VISIBILITE = 'visibilite_src_energie';

    protected $table = 'src_energie';

    protected $primaryKey = 'id_src_energie';
}
