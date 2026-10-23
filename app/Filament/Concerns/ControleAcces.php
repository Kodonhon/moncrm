<?php

namespace App\Filament\Concerns;

use App\Support\Acces;
use Illuminate\Database\Eloquent\Model;

/**
 * Droits d'une ressource Filament d'après les rôles et accès : la ressource déclare son module
 * (static $moduleAcces) ; voir / créer / modifier / supprimer se règlent dans Paramètres > Rôles et accès.
 * Une ressource interdite disparaît du menu.
 */
trait ControleAcces
{
    public static function canViewAny(): bool
    {
        return Acces::peut(static::$moduleAcces.'.voir');
    }

    public static function canView(Model $record): bool
    {
        return Acces::peut(static::$moduleAcces.'.voir');
    }

    public static function canCreate(): bool
    {
        return Acces::peut(static::$moduleAcces.'.creer');
    }

    public static function canEdit(Model $record): bool
    {
        return Acces::peut(static::$moduleAcces.'.modifier');
    }

    public static function canDelete(Model $record): bool
    {
        return Acces::peut(static::$moduleAcces.'.supprimer');
    }

    public static function canDeleteAny(): bool
    {
        return Acces::peut(static::$moduleAcces.'.supprimer');
    }
}
