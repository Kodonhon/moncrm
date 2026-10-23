<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Notifications du parcours client (cloche) : voir App\Support\NotificationsCrm
        \App\Support\NotificationsCrm::ecouter();

        // Rôles et accès : date de dernière connexion
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function ($evenement) {
            if (\App\Support\Acces::installe() && $evenement->user instanceof \App\Models\User) {
                $evenement->user->forceFill(['derniere_connexion' => now()])->saveQuietly();
            }
        });

        // Tables créées en production par des scripts SQL exécutés à la main (database/sql) :
        // en test (SQLite), elles sont créées par des migrations dédiées, jamais appliquées à Supabase.
        if ($this->app->runningUnitTests()) {
            $this->loadMigrationsFrom(database_path('migrations_tests'));
        }
    }
}
