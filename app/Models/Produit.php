<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;

/** Produit proposé lors de l'évaluation du site (kit, pompe…). */
class Produit extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_prod';

    public const COLONNE_VISIBILITE = 'visibilite_prod';

    protected $table = 'produit';

    protected $primaryKey = 'id_prod';

    /** Colonne de la commission (ajoutée par database/sql/produit.sql) : présente ? */
    public static function commissionDisponible(): bool
    {
        return \Illuminate\Support\Facades\Cache::remember('produit-commission-disponible', now()->addMinutes(10),
            fn () => \Illuminate\Support\Facades\Schema::hasColumn('produit', 'montant_com_commer'));
    }
}
