<?php

namespace App\Support;

use App\Models\Entite;

/**
 * Entité choisie dans la barre du haut (Greeno, Tevia… ou toutes), mémorisée dans la session.
 * Elle filtre les clients, KYC, évaluations et contrats (voir App\Models\Scopes\EntiteActiveScope).
 * Jamais appliquée en ligne de commande (imports, scripts).
 */
class EntiteActive
{
    private const CLE = 'entite_active';

    public static function id(): ?int
    {
        if (app()->runningInConsole() || ! request()->hasSession()) {
            return null;
        }

        $id = request()->session()->get(self::CLE);

        return filled($id) ? (int) $id : null;
    }

    public static function choisir(?int $id): void
    {
        $id && Entite::query()->whereKey($id)->exists()
            ? request()->session()->put(self::CLE, $id)
            : request()->session()->forget(self::CLE);
    }

    public static function libelle(): string
    {
        return ($id = self::id()) ? (CacheReferences::libelle(Entite::class, $id) ?? 'Entité '.$id) : 'Toutes les entités';
    }

    /** @return array<int, string> entités actives [id => libellé] */
    public static function options(): array
    {
        return Entite::options(self::id());
    }
}
