<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table client reprise du système GREENO existant (mêmes noms de colonnes que client.csv).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client', function (Blueprint $table) {
            $table->string('id_client', 20)->primary();
            $table->unsignedInteger('id_canal_vente')->nullable()->index();
            $table->unsignedInteger('id_type_piece_id')->nullable();
            $table->integer('id_supp_cnl')->nullable();
            $table->unsignedInteger('id_localite_commune')->nullable()->index();
            $table->string('nom_client', 100)->nullable();
            $table->string('prenoms_client', 100)->nullable();
            $table->date('date_client')->nullable()->index();
            $table->unsignedInteger('genre_client')->nullable();
            $table->string('contact1_client', 20)->nullable()->index();
            $table->string('contact2_client', 20)->nullable();
            $table->string('email_client')->nullable();
            $table->string('indication_site_client')->nullable();
            $table->string('id_client_amt_pros', 50)->nullable();
            $table->string('id_client_surge', 50)->nullable();
            $table->string('id_client_zeci', 50)->nullable();
            $table->smallInteger('visibilite_client')->nullable()->default(1);
            // Pas de clé étrangère : 2 clients d'origine portent l'entité n°3, absente de la table entite
            $table->unsignedInteger('entite_id_client')->nullable()->index();
            $table->unsignedInteger('id_src_client')->nullable();
            $table->string('id_act_pros', 50)->nullable();
            $table->string('systeme_souhaite_client', 100)->nullable();
            $table->string('profession_client', 100)->nullable();
            $table->string('horizon_achat_client', 100)->nullable();
            $table->string('connu_via_client', 100)->nullable();

            $table->foreign('id_canal_vente')->references('id_canal_vente')->on('canal_vente');
            $table->foreign('id_type_piece_id')->references('id_type_piece_id')->on('type_piece_id');
            $table->foreign('id_localite_commune')->references('id_localite_commune')->on('localite_commune');
            $table->foreign('genre_client')->references('id_genre')->on('genre');
            $table->foreign('id_src_client')->references('id_src_client')->on('src_client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client');
    }
};
