<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Colonne smallint (0 / 1) utilisée comme un oui / non.
 * Lecture : true / false (le code peut continuer à comparer avec === false).
 * Écriture : 1 / 0 — jamais « true » / « false », que PostgreSQL refuse pour un smallint
 * (requêtes préparées émulées : DB_EMULATE_PREPARES=true).
 */
class BooleenEntier implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?bool
    {
        return $value === null ? null : (bool) (int) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}
