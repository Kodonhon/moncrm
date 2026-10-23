<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables de référence (« tables intermédiaires ») reprises du système GREENO existant.
 * Les noms de tables et de colonnes sont ceux des fichiers CSV d'origine.
 * Chaque table a une colonne de visibilité : 1 = actif, 0 = désactivé (masqué des listes de choix).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genre', function (Blueprint $table) {
            $table->increments('id_genre');
            $table->string('lib_genre', 50);
            $table->smallInteger('vis_genre')->default(1);
        });

        Schema::create('entite', function (Blueprint $table) {
            $table->increments('id_entite');
            $table->string('lib_entite', 100);
            $table->string('desc_entite')->nullable();
            $table->smallInteger('vis_entite')->default(1);
        });

        Schema::create('src_client', function (Blueprint $table) {
            $table->increments('id_src_client');
            $table->string('lib_src_client', 100);
            $table->smallInteger('visibilite_src_client')->default(1);
        });

        Schema::create('type_piece_id', function (Blueprint $table) {
            $table->increments('id_type_piece_id');
            $table->string('lib_type_piece_id', 100);
            $table->smallInteger('visibilite_type_piece_id')->default(1);
        });

        Schema::create('site_shop', function (Blueprint $table) {
            $table->increments('id_site_shop');
            $table->string('lib_site_shop', 100);
            $table->string('desc_site_shop')->nullable();
            $table->string('comment_site_shop')->nullable();
            // Absente du fichier d'origine : ajoutée pour pouvoir désactiver un site depuis Paramètres
            $table->smallInteger('visibilite_site_shop')->default(1);
        });

        // Pas de fichier d'origine : les 112 id_ville de localite_commune, nommés d'après leur localité principale
        Schema::create('ville', function (Blueprint $table) {
            $table->increments('id_ville');
            $table->string('lib_ville', 100);
            $table->smallInteger('visibilite_ville')->default(1);
        });

        Schema::create('canal_vente', function (Blueprint $table) {
            $table->increments('id_canal_vente');
            $table->unsignedInteger('id_src_client')->nullable();
            $table->string('lib_canal_vente', 100);
            $table->smallInteger('visibilite_canal_vente')->default(1);
            $table->unsignedInteger('id_entite')->nullable();
            $table->string('test')->nullable();

            $table->foreign('id_src_client')->references('id_src_client')->on('src_client');
            $table->foreign('id_entite')->references('id_entite')->on('entite');
        });

        Schema::create('localite_commune', function (Blueprint $table) {
            $table->increments('id_localite_commune');
            $table->unsignedInteger('id_ville')->nullable();
            $table->unsignedInteger('id_site_shop')->nullable();
            $table->string('lib_localite', 100);
            $table->smallInteger('visibilite_localite_commune')->default(1);
            $table->string('gps_localite', 50)->nullable();

            $table->foreign('id_ville')->references('id_ville')->on('ville');
            $table->foreign('id_site_shop')->references('id_site_shop')->on('site_shop');
        });
    }

    public function down(): void
    {
        foreach (['localite_commune', 'canal_vente', 'ville', 'site_shop', 'type_piece_id', 'src_client', 'entite', 'genre'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
