<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use App\Support\Acces;
use App\Support\CacheReferences;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * Garde-fou : avec la configuration en cache (php artisan optimize / config:cache), Laravel ignore
     * phpunit.xml et les tests (RefreshDatabase) viseraient la vraie base Supabase. On refuse de démarrer.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if ($app['config']->get('database.default') !== 'sqlite') {
            throw new \RuntimeException('Tests arrêtés : la configuration est en cache et pointe sur la vraie base. Lancez « php artisan config:clear » avant les tests.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Le cache des tables de référence est gardé en mémoire : on repart de zéro à chaque test
        CacheReferences::viderMemoire();
        Acces::oublier();
    }

    /**
     * Un utilisateur de test sans rôle devient administrateur, comme les comptes existants
     * à l'installation des accès (crm:installer-acces). Les tests d'accès lui donnent un rôle précis avant.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($user instanceof User && $user->exists && Schema::hasTable('crm_roles') && $user->roles()->doesntExist()) {
            $administrateur = Role::firstOrCreate(['code' => 'administrateur'], [
                'nom' => 'Administrateur', 'perimetre' => 'tout', 'couleur' => 'rouge', 'systeme' => true,
            ]);
            $user->roles()->attach($administrateur->id, ['created_at' => now()]);
        }

        Acces::oublier();

        return parent::actingAs($user, $guard);
    }
}
