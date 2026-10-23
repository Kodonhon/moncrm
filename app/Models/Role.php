<?php

namespace App\Models;

use App\Support\Acces;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/** Rôle du CRM : un ensemble de permissions et un périmètre de données. */
class Role extends Model
{
    protected $table = 'crm_roles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['systeme' => 'boolean'];
    }

    protected static function booted(): void
    {
        $oublier = function () {
            Acces::oublier();
            Cache::forget('acces-installe');
        };
        static::saved($oublier);
        static::deleted($oublier);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role_id');
    }

    public function utilisateurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'crm_role_utilisateur', 'role_id', 'user_id');
    }

    public function estAdministrateur(): bool
    {
        return $this->code === 'administrateur';
    }

    /** @return array<int, string> */
    public function listePermissions(): array
    {
        return $this->permissions()->pluck('permission')->all();
    }

    /** Remplace les permissions du rôle par celles données. */
    public function synchroniserPermissions(array $permissions): void
    {
        $permissions = array_values(array_unique(array_filter($permissions)));
        $this->permissions()->whereNotIn('permission', $permissions ?: [''])->delete();
        $existantes = $this->permissions()->pluck('permission')->all();

        foreach (array_diff($permissions, $existantes) as $permission) {
            $this->permissions()->create(['permission' => $permission]);
        }

        Acces::oublier();
    }
}
