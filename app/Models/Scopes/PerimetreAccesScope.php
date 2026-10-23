<?php

namespace App\Models\Scopes;

use App\Models\Client;
use App\Support\Acces;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Chaque utilisateur ne voit que les clients de son périmètre (rôles et accès) :
 * les clients directement, et les KYC / évaluations / contrats à travers leur client.
 */
class PerimetreAccesScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($model instanceof Client) {
            Acces::filtrerClients($builder, $model->qualifyColumn('entite_id_client'), $model->qualifyColumn('id_agent'), $model->qualifyColumn('id_localite_commune'));

            return;
        }

        if (Acces::installe() && auth()->check() && ! Acces::estAdministrateur()) {
            $builder->whereHas('client');
        }
    }
}
