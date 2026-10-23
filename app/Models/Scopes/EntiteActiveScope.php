<?php

namespace App\Models\Scopes;

use App\Models\Client;
use App\Support\EntiteActive;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtre sur l'entité choisie dans la barre du haut :
 * les clients par leur entité, et les KYC / évaluations / contrats par l'entité de leur client.
 */
class EntiteActiveScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! $id = EntiteActive::id()) {
            return;
        }

        $model instanceof Client
            ? $builder->where($model->qualifyColumn('entite_id_client'), $id)
            : $builder->whereHas('client');
    }
}
