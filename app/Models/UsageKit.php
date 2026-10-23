<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;

/** Usage prévu du kit (agriculture, domestique…). */
class UsageKit extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_usg_kit';

    public const COLONNE_VISIBILITE = 'visibilite_kit';

    protected $table = 'usage_kit';

    protected $primaryKey = 'id_usg_kit';
}
