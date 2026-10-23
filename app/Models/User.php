<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'id_agent', 'actif', 'entites_autorisees', 'derniere_connexion', 'avatar_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, \Filament\Models\Contracts\HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Double authentification (Breezy), active seulement si le paquet est installé
    use \App\Models\Concerns\DoubleAuthentification;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'entites_autorisees' => 'array',
            'derniere_connexion' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        // Compte modifié (actif, agent, rôles via le formulaire) : les droits en cache sont recalculés
        static::saved(fn () => \App\Support\Acces::oublier());
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return \App\Support\Acces::peutSeConnecter($this);
    }

    /** Rôles du CRM (Paramètres > Rôles et permissions). */
    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'crm_role_utilisateur', 'user_id', 'role_id')->withPivot('created_at');
    }

    /** Agent rattaché : détermine « ses clients » et « son équipe ». */
    public function agent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Agent::class, 'id_agent');
    }

    /** Photo de profil (page « Mon profil »), sinon les initiales. */
    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar_url) : null;
    }

    public function peut(string $permission): bool
    {
        return \App\Support\Acces::peut($permission, $this);
    }
}
