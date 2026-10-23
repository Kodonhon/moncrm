<?php

use App\Formulaires\ChampsParDefaut;
use App\Formulaires\ListesReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Évaluation du site du client (table « eval_site » du système existant, mêmes noms de colonnes),
 * ses tables de référence, et les questions / sections par défaut du formulaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produit', function (Blueprint $table) {
            $table->increments('id_prod');
            $table->string('lib_prod', 150);
            $table->integer('debit_prod')->nullable();
            $table->smallInteger('visibilite_prod')->default(1);
            $table->text('desc_prod')->nullable();
        });

        Schema::create('usage_kit', function (Blueprint $table) {
            $table->increments('id_usg_kit');
            $table->string('lib_usg_kit', 100);
            $table->string('entite_usg_kit', 10)->nullable();
            $table->smallInteger('visibilite_kit')->default(1);
        });

        Schema::create('src_energie', function (Blueprint $table) {
            $table->increments('id_src_energie');
            $table->string('lib_src_energie', 100);
            $table->string('desc_src_energie')->nullable();
            $table->smallInteger('visibilite_src_energie')->default(1);
        });

        // Pas de fichier d'origine : types d'évaluation, modifiables dans Paramètres
        Schema::create('type_eval_site', function (Blueprint $table) {
            $table->increments('id_type_eval_site');
            $table->string('lib_type_eval_site', 100);
            $table->smallInteger('visibilite_type_eval_site')->default(1);
        });
        DB::table('type_eval_site')->insert([
            ['id_type_eval_site' => 1, 'lib_type_eval_site' => 'Pompage solaire (TDH)', 'visibilite_type_eval_site' => 1],
            ['id_type_eval_site' => 2, 'lib_type_eval_site' => 'Kit solaire domestique (SHS)', 'visibilite_type_eval_site' => 1],
        ]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("select setval(pg_get_serial_sequence('type_eval_site', 'id_type_eval_site'), 3, false)");
        }

        $champs = ChampsParDefaut::evalSite();
        $references = ['agent' => 'agent', 'type_eval_site' => 'type_eval_site', 'usage_kit' => 'usage_kit', 'produit' => 'produit', 'src_energie' => 'src_energie'];

        Schema::create('eval_site', function (Blueprint $table) use ($champs, $references) {
            $table->string('id_eval_site', 20)->primary();
            $table->string('id_client', 20)->index();
            $table->unsignedInteger('id_conv')->nullable(); // non utilisé
            // Marqueurs de section du système d'origine (general, tdh, shs, sst) : conservés, non utilisés
            foreach (['general', 'tdh', 'shs', 'sst'] as $marqueur) {
                $table->string($marqueur)->nullable();
            }

            // Une colonne par question, typée d'après la question par défaut
            foreach ($champs as $champ) {
                $source = $champ['source_liste'] ?? null;
                match (true) {
                    $champ['cle'] === 'statut_eval_site' => $table->smallInteger('statut_eval_site')->nullable()->default(0),
                    $champ['type'] === 'date' => $table->date($champ['cle'])->nullable(),
                    $champ['type'] === 'oui_non' => $table->boolean($champ['cle'])->nullable(),
                    in_array($champ['type'], ['gps_latitude', 'gps_longitude'], true) => $table->decimal($champ['cle'], 10, 7)->nullable(),
                    $champ['type'] === 'zone_texte' => $table->text($champ['cle'])->nullable(),
                    $source !== null && $source !== ListesReference::PERSONNALISEE => $table->unsignedInteger($champ['cle'])->nullable(),
                    default => $table->string($champ['cle'])->nullable(),
                };
            }

            $table->json('donnees_formulaire')->nullable();

            $table->foreign('id_client')->references('id_client')->on('client')->cascadeOnDelete();
            foreach ($champs as $champ) {
                if (isset($references[$champ['source_liste'] ?? '']) && $champ['source_liste'] !== 'agent') {
                    $cleReference = ['type_eval_site' => 'id_type_eval_site', 'usage_kit' => 'id_usg_kit', 'produit' => 'id_prod', 'src_energie' => 'id_src_energie'][$champ['source_liste']];
                    $table->foreign($champ['cle'])->references($cleReference)->on($champ['source_liste']);
                }
            }
        });

        DB::table('formulaire_champ')->insert(ChampsParDefaut::pourInsertion($champs));

        DB::table('formulaire_section')->insert(array_map(fn (array $section) => [
            'formulaire' => 'eval_site',
            'nom' => $section['nom'],
            'ordre' => $section['ordre'],
            'visible' => true,
            'description' => $section['description'],
            'conditions' => $section['conditions'] ? json_encode($section['conditions'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], ChampsParDefaut::sectionsEvalSite()));
    }

    public function down(): void
    {
        DB::table('formulaire_section')->where('formulaire', 'eval_site')->delete();
        DB::table('formulaire_champ')->where('formulaire', 'eval_site')->delete();

        foreach (['eval_site', 'type_eval_site', 'src_energie', 'usage_kit', 'produit'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
