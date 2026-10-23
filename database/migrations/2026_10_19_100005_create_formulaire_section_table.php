<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sections paramétrables des formulaires (ordre, description, affichage, conditions),
 * et possibilité de changer le type de réponse de certaines questions d'origine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulaire_section', function (Blueprint $table) {
            $table->id();
            $table->string('formulaire', 50)->index();
            $table->string('nom', 100);
            $table->unsignedInteger('ordre')->default(0);
            $table->boolean('visible')->default(true);
            $table->string('description')->nullable();
            $table->json('conditions')->nullable(); // [{champ, operateur, valeurs}] : toutes doivent être vraies
            $table->timestamps();

            $table->unique(['formulaire', 'nom']);
        });

        // Question d'origine dont la colonne accepte plusieurs types de réponse (texte, nombre, liste…)
        Schema::table('formulaire_champ', function (Blueprint $table) {
            $table->boolean('type_modifiable')->default(false);
        });

        // Sections existantes des formulaires client et KYC, dans l'ordre de leur première question
        DB::table('formulaire_champ')
            ->selectRaw('formulaire, section, min(ordre) as ordre')
            ->groupBy('formulaire', 'section')
            ->get()
            ->each(fn ($ligne) => DB::table('formulaire_section')->insert([
                'formulaire' => $ligne->formulaire,
                'nom' => $ligne->section,
                'ordre' => $ligne->ordre,
                'visible' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    }

    public function down(): void
    {
        Schema::table('formulaire_champ', function (Blueprint $table) {
            $table->dropColumn('type_modifiable');
        });

        Schema::dropIfExists('formulaire_section');
    }
};
