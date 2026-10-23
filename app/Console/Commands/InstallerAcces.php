<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\Acces;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

#[Signature('crm:installer-acces
    {email : E-mail du premier administrateur (créé s\'il n\'existe pas)}
    {--nom= : Nom affiché de l\'administrateur}
    {--mot-de-passe= : Mot de passe (sinon il est demandé)}
    {--agent= : Numéro d\'agent à rattacher à ce compte}
    {--reinitialiser-roles : Remettre les permissions des rôles par défaut à leur valeur d\'origine}')]
#[Description('Installe les rôles et accès : rôles par défaut, premier administrateur, et rôle administrateur pour les comptes existants')]
class InstallerAcces extends Command
{
    public function handle(): int
    {
        if (! Schema::hasTable('crm_roles')) {
            $this->error('Exécutez d\'abord le script database/sql/acces.sql sur la base.');

            return self::FAILURE;
        }

        Cache::forget('acces-installe');

        // 1. Rôles par défaut
        foreach (Acces::ROLES_PAR_DEFAUT as $code => [$nom, $description, $couleur, $perimetre, $permissions]) {
            $role = Role::firstOrNew(['code' => $code]);
            $nouveau = ! $role->exists;
            $role->fill(['nom' => $role->nom ?: $nom, 'description' => $role->description ?: $description,
                'couleur' => $role->couleur ?: $couleur, 'perimetre' => $nouveau ? $perimetre : $role->perimetre,
                'systeme' => $code === 'administrateur'])->save();

            if ($nouveau || $this->option('reinitialiser-roles')) {
                $role->synchroniserPermissions($permissions === ['*'] ? Acces::toutesLesPermissions() : $permissions);
            }

            $this->line(($nouveau ? '  + ' : '  = ').$role->nom.' ('.count($role->listePermissions()).' permissions)');
        }

        // 2. Premier administrateur
        $email = Str::lower($this->argument('email'));
        $utilisateur = User::firstOrNew(['email' => $email]);
        if (! $utilisateur->exists || $this->option('mot-de-passe')) {
            $motDePasse = $this->option('mot-de-passe') ?: $this->secret('Mot de passe de '.$email);
            if (blank($motDePasse) || strlen($motDePasse) < 8) {
                $this->error('Mot de passe obligatoire (8 caractères minimum).');

                return self::FAILURE;
            }
            $utilisateur->password = Hash::make($motDePasse);
        }
        $utilisateur->name = $this->option('nom') ?: ($utilisateur->name ?: Str::before($email, '@'));
        $utilisateur->actif = true;
        if ($this->option('agent')) {
            $utilisateur->id_agent = (int) $this->option('agent');
        }
        $utilisateur->save();

        $admin = Role::where('code', 'administrateur')->first();
        $utilisateur->roles()->syncWithoutDetaching([$admin->id => ['created_at' => now()]]);

        // 3. Comptes existants sans rôle (ex. compte de connexion automatique) : administrateurs, pour ne bloquer personne
        $sansRole = User::whereDoesntHave('roles')->get();
        foreach ($sansRole as $compte) {
            $compte->roles()->syncWithoutDetaching([$admin->id => ['created_at' => now()]]);
        }

        Acces::oublier();
        $this->info("Accès installés. Administrateur : {$email}".($sansRole->count() ? " ({$sansRole->count()} autre(s) compte(s) existant(s) passé(s) administrateur — à revoir dans Rôles et accès)." : '.'));
        $this->warn('Pour activer la connexion par mot de passe : mettez CRM_CONNEXION_AUTO=false dans .env, puis php artisan optimize:clear.');

        return self::SUCCESS;
    }
}
