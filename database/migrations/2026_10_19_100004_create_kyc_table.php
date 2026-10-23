<?php

use App\Formulaires\ChampsParDefaut;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KYC des clients (table « kyc » reprise du système existant, mêmes noms de colonnes)
 * et table de référence des types de vente, plus les questions par défaut du formulaire KYC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('type_vente', function (Blueprint $table) {
            $table->increments('id_type_vente');
            $table->string('lib_type_vente', 100);
            $table->smallInteger('visibilite_type_vente')->default(1);
        });

        // Lien de parenté entre le client et la personne à contacter.
        // Pas de fichier d'origine : libellés déduits du texte saisi dans les KYC pour chaque id_lien_par.
        Schema::create('lien_par', function (Blueprint $table) {
            $table->increments('id_lien_par');
            $table->string('lib_lien_par', 100);
            $table->smallInteger('visibilite_lien_par')->default(1);
        });

        DB::table('lien_par')->insert(array_map(fn ($id, $libelle) => ['id_lien_par' => $id, 'lib_lien_par' => $libelle, 'visibilite_lien_par' => 1], array_keys($liens = [
            1 => 'Père', 2 => 'Mère', 3 => 'Fils', 4 => 'Fille', 5 => 'Frère', 6 => 'Sœur', 7 => 'Époux', 8 => 'Épouse', 9 => 'Conjoint(e)',
            14 => 'Oncle', 16 => 'Neveu', 17 => 'Nièce', 18 => 'Cousin(e)', 22 => 'Beau-frère', 27 => 'Belle-fille', 28 => 'Tuteur / Tutrice',
            30 => 'Collaborateur', 31 => 'Autre',
        ]), $liens));

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("select setval(pg_get_serial_sequence('lien_par', 'id_lien_par'), 32, false)");
        }

        Schema::create('kyc', function (Blueprint $table) {
            $table->string('id_kyc', 20)->primary();
            $table->unsignedInteger('id_type_vente')->nullable();
            // Pas de clé étrangère : les agents du système d'origine (ex. n° 1024) ne sont pas encore dans la table agent
            $table->unsignedInteger('id_agent')->nullable()->index();
            $table->unsignedInteger('id_lien_par')->nullable();
            $table->string('id_client', 20)->index();
            $table->date('date_kyc')->nullable();
            $table->date('date_edit_doc')->nullable();
            $table->date('date_expir_doc')->nullable();
            $table->string('email')->nullable();
            $table->unsignedInteger('type_piece_id')->nullable();
            $table->string('num_piece_id', 30)->nullable();
            $table->date('d_nais')->nullable();
            $table->string('photo_id_face1')->nullable();
            $table->string('photo_id_face2')->nullable();
            $table->string('photo_id_av_client')->nullable();
            $table->string('nom_parent_proche', 100)->nullable();
            $table->string('lien_parente', 100)->nullable();
            $table->string('contact_parent_1', 20)->nullable();
            $table->string('contact_parent_2', 20)->nullable();
            $table->string('ref_client_upya', 30)->nullable();
            $table->smallInteger('visibilite_kyc')->nullable()->default(1);
            $table->unsignedInteger('entite')->nullable();
            // Réponses aux questions ajoutées dans Paramètres > Formulaire KYC
            $table->json('donnees_formulaire')->nullable();

            $table->foreign('id_client')->references('id_client')->on('client')->cascadeOnDelete();
            $table->foreign('id_type_vente')->references('id_type_vente')->on('type_vente');
            $table->foreign('type_piece_id')->references('id_type_piece_id')->on('type_piece_id');
            $table->foreign('id_lien_par')->references('id_lien_par')->on('lien_par');
        });

        DB::table('formulaire_champ')->insert(ChampsParDefaut::pourInsertion(ChampsParDefaut::kyc()));
    }

    public function down(): void
    {
        DB::table('formulaire_champ')->where('formulaire', 'kyc')->delete();
        Schema::dropIfExists('kyc');
        Schema::dropIfExists('lien_par');
        Schema::dropIfExists('type_vente');
    }
};
