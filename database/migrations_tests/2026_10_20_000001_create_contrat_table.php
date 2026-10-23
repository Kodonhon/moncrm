<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TESTS UNIQUEMENT (base SQLite) : même table que database/sql/contrat_regles.sql.
 * Chargée seulement quand les tests tournent (AppServiceProvider) : jamais appliquée à Supabase,
 * où la table est créée par le script SQL relu et exécuté à la main.
 * Le trigger PostgreSQL n'existe pas en SQLite : les règles y sont vérifiées par App\Contrats\CreerContrat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrat', function (Blueprint $table) {
            $table->string('id_contrat', 20)->primary();
            $table->string('num_contrat', 30)->unique();
            $table->string('id_client', 20)->index();
            $table->unsignedInteger('id_prod')->nullable();
            $table->unsignedInteger('id_type_vente')->nullable();
            $table->decimal('montant', 14, 2)->nullable();
            $table->date('date_contrat');
            $table->string('statut', 20)->default('actif');
            $table->unsignedInteger('id_agent')->nullable();
            $table->timestamps();
            $table->string('id_eval', 20)->nullable()->unique();
            $table->string('id_kyc', 20)->nullable();

            $table->foreign('id_client')->references('id_client')->on('client');
            $table->foreign('id_eval')->references('id_eval_site')->on('eval_site');
            $table->foreign('id_kyc')->references('id_kyc')->on('kyc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrat');
    }
};
