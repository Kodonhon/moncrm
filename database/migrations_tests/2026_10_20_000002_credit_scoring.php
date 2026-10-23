<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TESTS UNIQUEMENT (base SQLite) : mêmes colonnes que database/sql/credit_scoring.sql.
 * Jamais appliquée à Supabase, où le script SQL est relu et exécuté à la main.
 * Les questions du formulaire sont installées par la commande crm:credit-scoring (comme en production).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent', function (Blueprint $table) {
            $table->string('role_agent', 20)->default('terrain');
        });

        Schema::table('kyc', function (Blueprint $table) {
            $table->smallInteger('statut_validation_kyc')->default(0);
            $table->text('motif_validation_kyc')->nullable();
            $table->timestamp('date_validation_kyc')->nullable();
            $table->unsignedInteger('id_agent_validation_kyc')->nullable();
        });

        Schema::create('credit_scoring', function (Blueprint $table) {
            $table->string('id_cs', 20)->primary();
            $table->string('id_client', 20)->nullable()->index();
            $table->string('id_kyc', 20)->nullable();
            $table->unsignedInteger('id_activite')->nullable();
            $table->string('num_cni', 50)->nullable();
            $table->unsignedInteger('id_src_eau')->nullable();
            $table->unsignedInteger('id_periodicite')->nullable();
            $table->string('id_prospect', 20)->nullable();
            $table->unsignedInteger('id_agent')->nullable();
            $table->date('date_cs')->nullable();
            $table->time('heure_cs')->nullable();
            foreach ([...range(1, 15), '15_autre', ...range(16, 28), '28_autre', ...range(29, 47)] as $n) {
                $table->text(is_int($n) ? "q{$n}_cs" : 'q'.str_replace('_autre', '_cs_autre', $n))->nullable();
            }
            $table->string('id_prospect_appsheet', 30)->nullable();
            $table->smallInteger('visibilite_cs')->nullable()->default(1);
            $table->string('last_modified_by', 150)->nullable();
            $table->string('num_contrat_upya', 30)->nullable();
            $table->json('donnees_formulaire')->nullable();
            $table->timestamps();

            $table->foreign('id_client')->references('id_client')->on('client');
            $table->foreign('id_kyc')->references('id_kyc')->on('kyc');
            $table->foreign('id_agent')->references('id_agent')->on('agent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_scoring');
    }
};
