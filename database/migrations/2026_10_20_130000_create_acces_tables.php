<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles et accès : rôles, permissions de chaque rôle, rôles de chaque utilisateur,
 * et rattachement de l'utilisateur à un agent (périmètre « ses clients » / « son équipe »).
 * Uniquement des ajouts. Les rôles par défaut et le premier administrateur : php artisan crm:installer-acces
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_roles')) {
            Schema::create('crm_roles', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('nom', 100);
                $table->string('description', 255)->nullable();
                $table->string('couleur', 20)->default('violet');
                $table->string('perimetre', 20)->default('personnel'); // tout | entite | equipe | personnel
                $table->boolean('systeme')->default(false);           // rôle protégé (administrateur)
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crm_role_permissions')) {
            Schema::create('crm_role_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained('crm_roles')->cascadeOnDelete();
                $table->string('permission', 80);
                $table->unique(['role_id', 'permission']);
            });
        }

        if (! Schema::hasTable('crm_role_utilisateur')) {
            Schema::create('crm_role_utilisateur', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('role_id')->constrained('crm_roles')->cascadeOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->primary(['user_id', 'role_id']);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'id_agent')) {
                $table->unsignedInteger('id_agent')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'actif')) {
                $table->boolean('actif')->default(true);
            }
            if (! Schema::hasColumn('users', 'entites_autorisees')) {
                $table->json('entites_autorisees')->nullable();
            }
            if (! Schema::hasColumn('users', 'derniere_connexion')) {
                $table->timestamp('derniere_connexion')->nullable();
            }
        });

        Cache::forget('acces-installe');
    }

    /** Ne supprime rien en production (rôles et comptes). */
    public function down(): void
    {
    }
};
