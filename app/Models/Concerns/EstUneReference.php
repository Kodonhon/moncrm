<?php

namespace App\Models\Concerns;

use App\Support\CacheReferences;
use Illuminate\Database\Eloquent\Builder;

/**
 * Comportement commun des tables de référence GREENO (genre, entité, canal, localité…).
 * Chaque modèle déclare COLONNE_LIBELLE et COLONNE_VISIBILITE.
 * Les tables sont servies depuis le cache (voir CacheReferences), vidé à chaque modification.
 */
trait EstUneReference
{
    public static function bootEstUneReference(): void
    {
        static::saved(fn () => CacheReferences::oublier(static::class));
        static::deleted(fn () => CacheReferences::oublier(static::class));
    }

    public function initializeEstUneReference(): void
    {
        $this->timestamps = false;
        $this->guarded = [];
        // smallint 0/1 : écrit 1/0 (PostgreSQL refuse « true »), lu true/false
        $this->mergeCasts([static::COLONNE_VISIBILITE => \App\Casts\BooleenEntier::class]);
    }

    /** Éléments actifs uniquement (visibilité = 1). */
    public function scopeActifs(Builder $query): Builder
    {
        return $query->where(static::COLONNE_VISIBILITE, 1);
    }

    public function getLibelleAttribute(): ?string
    {
        return $this->{static::COLONNE_LIBELLE};
    }

    /**
     * Liste [id => libellé] pour les listes de choix : les éléments actifs,
     * plus l'élément déjà sélectionné même s'il a été désactivé depuis.
     */
    public static function options(int|string|null $valeurActuelle = null): array
    {
        return CacheReferences::tous(static::class)
            ->filter(fn ($element) => $element->{static::COLONNE_VISIBILITE}
                || (filled($valeurActuelle) && (string) $element->getKey() === (string) $valeurActuelle))
            ->sortBy(static::COLONNE_LIBELLE, SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn ($element) => [$element->getKey() => $element->{static::COLONNE_LIBELLE}])
            ->all();
    }

    /** Toutes les valeurs [id => libellé], actives ou non (filtres de liste). */
    public static function tousLesLibelles(): array
    {
        return CacheReferences::tous(static::class)
            ->sortBy(static::COLONNE_LIBELLE, SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn ($element) => [$element->getKey() => $element->{static::COLONNE_LIBELLE}])
            ->all();
    }
}
