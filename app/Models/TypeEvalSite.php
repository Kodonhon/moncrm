<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;

/** Type d'évaluation du site (pompage solaire, kit domestique…). */
class TypeEvalSite extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_type_eval_site';

    public const COLONNE_VISIBILITE = 'visibilite_type_eval_site';

    protected $table = 'type_eval_site';

    protected $primaryKey = 'id_type_eval_site';
}
