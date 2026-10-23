<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TESTS UNIQUEMENT (base SQLite) : même table que database/sql/assignation_kit.sql. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignation_kit', function (Blueprint $table) {
            $table->id();
            $table->string('id_client', 40)->index();
            $table->string('id_cs', 40)->nullable();
            $table->string('num_contrat_upya', 30)->nullable();
            $table->string('num_serie_controleur', 60);
            $table->string('num_serie_pompe', 60);
            $table->string('num_serie_panneau_1', 60);
            $table->string('num_serie_panneau_2', 60)->nullable();
            $table->integer('id_agent_technicien')->nullable();
            $table->date('date_assignation')->useCurrent();
            $table->string('statut', 20)->default('assigne');
            $table->text('commentaire')->nullable();
            $table->unsignedBigInteger('assigne_par')->nullable();
            $table->string('nom_assigneur', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignation_kit');
    }
};
