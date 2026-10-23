<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TESTS UNIQUEMENT (base SQLite) : mêmes colonnes que database/sql/installation.sql.
 * Jamais appliquée à Supabase, où le script SQL est relu et exécuté à la main.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation', function (Blueprint $table) {
            $table->string('id_installation', 20)->primary();
            $table->string('id_client', 20)->nullable();
            $table->date('date_installation')->nullable();
            $table->smallInteger('systeme_installe')->nullable();
            $table->smallInteger('kit_recu')->nullable();
            foreach (['num_cni' => 50, 'num_contrat' => 30, 'num_contrat_upya' => 30, 'type_installation' => 30, 'type_produit' => 150,
                'type_paiement' => 20, 'contact_client' => 30, 'ville' => 100, 'localite' => 150, 'geolocalisation' => 60,
                'prise_en_charge_par' => 150, 'assignee_a' => 150, 'statut_technicien' => 30, 'code_controleur' => 60,
                'num_serie_pompe' => 60, 'num_serie_panneau_1' => 60, 'num_serie_panneau_2' => 60] as $colonne => $taille) {
                $table->string($colonne, $taille)->nullable();
            }
            $table->text('raison_non_installation')->nullable();
            $table->text('cause_non_installation')->nullable();
            $table->json('donnees_origine')->nullable();
            $table->timestamps();

            $table->index(['id_client', 'systeme_installe']);
            $table->foreign('id_client')->references('id_client')->on('client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation');
    }
};
