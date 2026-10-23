<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Tables de référence (canaux, localités, villes, shops, agents…) gardées en cache.
 *
 * Elles sont petites et changent rarement, alors que la base est distante (≈150 ms par requête) :
 * on les lit une fois, puis on les sert depuis le cache jusqu'à la prochaine modification
 * (le cache est vidé automatiquement à l'enregistrement ou à la suppression d'un élément).
 */
class CacheReferences
{
    /** @var array<class-string, Collection<int|string, Model>> mémoire de la requête en cours */
    private static array $memoire = [];

    /** @param  class-string<Model>  $modele */
    public static function tous(string $modele): Collection
    {
        return self::$memoire[$modele] ??= collect(Cache::rememberForever(
            self::cle($modele),
            fn () => $modele::query()->get()->map->getAttributes()->all(),
        ))
            ->map(fn (array $attributs) => (new $modele)->newFromBuilder($attributs))
            ->keyBy(fn (Model $element) => (string) $element->getKey());
    }

    /** @param  class-string<Model>  $modele */
    public static function trouver(string $modele, int|string|null $id): ?Model
    {
        return $id === null || $id === '' ? null : self::tous($modele)->get((string) $id);
    }

    /** Libellé d'un élément (colonne COLONNE_LIBELLE du modèle). */
    public static function libelle(string $modele, int|string|null $id): ?string
    {
        return self::trouver($modele, $id)?->{$modele::COLONNE_LIBELLE};
    }

    public static function oublier(string $modele): void
    {
        unset(self::$memoire[$modele]);
        Cache::forget(self::cle($modele));
    }

    /** À appeler entre deux requêtes simulées (tests) pour repartir de zéro. */
    public static function viderMemoire(): void
    {
        self::$memoire = [];
    }

    private static function cle(string $modele): string
    {
        return 'reference:'.(new $modele)->getTable();
    }
}
