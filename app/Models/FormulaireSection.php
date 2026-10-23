<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Section d'un formulaire configurable : ordre, description, affichage et conditions d'affichage.
 */
class FormulaireSection extends Model
{
    protected $table = 'formulaire_section';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'conditions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Renommer une section renomme aussi la section de ses questions
        static::updating(function (FormulaireSection $section) {
            if ($section->isDirty('nom')) {
                FormulaireChamp::query()
                    ->where('formulaire', $section->formulaire)
                    ->where('section', $section->getOriginal('nom'))
                    ->update(['section' => $section->nom]);
                Cache::forget(FormulaireChamp::cleCache($section->formulaire));
            }
        });

        $oublier = fn (FormulaireSection $section) => Cache::forget(static::cleCache($section->formulaire));
        static::saved($oublier);
        static::deleted($oublier);
    }

    public static function cleCache(string $formulaire): string
    {
        return "formulaire-sections:{$formulaire}";
    }

    /** @return Collection<string, FormulaireSection> sections du formulaire, indexées par nom */
    public static function pour(string $formulaire): Collection
    {
        return collect(Cache::rememberForever(
            static::cleCache($formulaire),
            fn () => static::query()->where('formulaire', $formulaire)->orderBy('ordre')->get()->map->getAttributes()->all(),
        ))
            ->map(fn (array $attributs) => (new static)->newFromBuilder($attributs))
            ->keyBy('nom');
    }
}
