<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le CRM s'utilise sans page de connexion : chaque visiteur est connecté
 * automatiquement avec le premier utilisateur (créé s'il n'existe pas).
 * À remplacer par une vraie authentification avant toute mise en ligne.
 */
class ConnexionAutomatique
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('crm.connexion_automatique') && ! Auth::check()) {
            $utilisateur = User::query()->orderBy('id')->first()
                ?? User::create([
                    'name' => 'GREENO',
                    'email' => 'crm@greeno.local',
                    'password' => Str::random(40),
                ]);

            // Compte de connexion automatique sans rôle : administrateur (comme crm:installer-acces pour les comptes existants)
            if (\App\Support\Acces::installe() && $utilisateur->roles()->doesntExist()
                && ($administrateur = \App\Models\Role::where('code', 'administrateur')->first()
                    ?? \App\Models\Role::create(['code' => 'administrateur', 'nom' => 'Administrateur', 'perimetre' => 'tout', 'couleur' => 'rouge', 'systeme' => true]))) {
                $utilisateur->roles()->attach($administrateur->id, ['created_at' => now()]);
                \App\Support\Acces::oublier();
            }

            Auth::login($utilisateur);
        }

        return $next($request);
    }
}
