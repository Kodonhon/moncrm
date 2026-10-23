<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agents qui enregistrent les clients (absents du système d'origine) :
 * table de référence gérée dans Paramètres > Agents, et question « Agent » du formulaire client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent', function (Blueprint $table) {
            $table->increments('id_agent');
            $table->string('nom_agent', 150);
            $table->string('contact_agent', 20)->nullable();
            $table->unsignedInteger('id_site_shop')->nullable();
            $table->smallInteger('visibilite_agent')->default(1);

            $table->foreign('id_site_shop')->references('id_site_shop')->on('site_shop');
        });

        Schema::table('client', function (Blueprint $table) {
            $table->unsignedInteger('id_agent')->nullable()->index();
            $table->foreign('id_agent')->references('id_agent')->on('agent');
        });

        // Question placée juste après la date d'enregistrement
        $ordreDate = DB::table('formulaire_champ')->where('formulaire', 'client')->where('cle', 'date_client')->value('ordre') ?? 0;

        DB::table('formulaire_champ')->insert([
            'formulaire' => 'client',
            'cle' => 'id_agent',
            'libelle' => 'Agent',
            'type' => 'liste',
            'standard' => true,
            'stockage' => 'colonne',
            'section' => 'Suivi',
            'ordre' => $ordreDate + 5,
            'visible' => true,
            'obligatoire' => false,
            'verrouille' => false,
            'pleine_largeur' => false,
            'aide' => 'Agent qui a enregistré le client.',
            'source_liste' => 'agent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('formulaire_champ')->where('formulaire', 'client')->where('cle', 'id_agent')->delete();

        Schema::table('client', function (Blueprint $table) {
            $table->dropForeign(['id_agent']);
            $table->dropColumn('id_agent');
        });

        Schema::dropIfExists('agent');
    }
};
