<?php

namespace App\Models;

use App\Models\Concerns\EstUneReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanalVente extends Model
{
    use EstUneReference;

    public const COLONNE_LIBELLE = 'lib_canal_vente';

    public const COLONNE_VISIBILITE = 'visibilite_canal_vente';

    protected $table = 'canal_vente';

    protected $primaryKey = 'id_canal_vente';

    public function source(): BelongsTo
    {
        return $this->belongsTo(SourceClient::class, 'id_src_client');
    }

    public function entite(): BelongsTo
    {
        return $this->belongsTo(Entite::class, 'id_entite');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'id_canal_vente');
    }
}
