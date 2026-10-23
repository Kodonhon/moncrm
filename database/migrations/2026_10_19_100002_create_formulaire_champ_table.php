<?php

use App\Formulaires\ChampsParDefaut;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formulaires configurables : chaque ligne décrit une question (ordre, section, visibilité,
 * conditions d'affichage, liste déroulante éventuellement filtrée par une autre question).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulaire_champ', function (Blueprint $table) {
            $table->id();
            $table->string('formulaire', 50)->index();
            $table->string('cle', 100);
            $table->string('libelle');
            $table->string('type', 20);
            $table->boolean('standard')->default(false);     // colonne d'origine de la table (ne peut pas être supprimée)
            $table->string('stockage', 20)->default('perso'); // colonne | perso (donnees_formulaire) | aucun (filtre seulement)
            $table->string('section', 100)->default('Autres');
            $table->unsignedInteger('ordre')->default(0);
            $table->boolean('visible')->default(true);
            $table->boolean('obligatoire')->default(false);
            $table->boolean('verrouille')->default(false);   // toujours affichée
            $table->boolean('pleine_largeur')->default(false);
            $table->string('aide')->nullable();
            $table->string('defaut')->nullable();
            $table->string('source_liste', 50)->nullable();
            $table->json('options')->nullable();             // liste personnalisée : [{valeur, libelle, parent}]
            $table->string('liste_parent', 100)->nullable(); // question dont dépend la liste
            $table->string('liste_parent_colonne', 100)->nullable();
            $table->json('conditions')->nullable();          // [{champ, operateur, valeurs}] : toutes doivent être vraies
            $table->timestamps();

            $table->unique(['formulaire', 'cle']);
        });

        // Réponses aux questions ajoutées dans Paramètres (hors colonnes d'origine)
        Schema::table('client', function (Blueprint $table) {
            $table->json('donnees_formulaire')->nullable();
        });

        DB::table('formulaire_champ')->insert(array_map(fn (array $champ) => array_merge($champ, [
            'options' => isset($champ['options']) ? json_encode($champ['options'], JSON_UNESCAPED_UNICODE) : null,
            'conditions' => isset($champ['conditions']) ? json_encode($champ['conditions'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]), array_map(fn (array $champ) => $champ + [
            'aide' => null, 'defaut' => null, 'source_liste' => null, 'options' => null,
            'liste_parent' => null, 'liste_parent_colonne' => null, 'conditions' => null,
        ], ChampsParDefaut::client())));
    }

    public function down(): void
    {
        Schema::table('client', function (Blueprint $table) {
            $table->dropColumn('donnees_formulaire');
        });

        Schema::dropIfExists('formulaire_champ');
    }
};
