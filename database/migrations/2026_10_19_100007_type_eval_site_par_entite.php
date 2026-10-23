<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Types d'évaluation propres à une entité : Pompage solaire pour Greeno, Kit solaire domestique pour Tevia
 * (0 = All : proposé à tous). Le formulaire d'évaluation reprend l'entité du client et filtre les types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('type_eval_site', function (Blueprint $table) {
            $table->unsignedInteger('id_entite')->default(0);
        });

        DB::table('type_eval_site')->where('id_type_eval_site', 1)->update(['id_entite' => 1]); // Greeno
        DB::table('type_eval_site')->where('id_type_eval_site', 2)->update(['id_entite' => 2]); // Tevia

        // « Entité du client » : reprise de la fiche du client, en lecture seule, non enregistrée ; elle filtre les types
        $ordreType = DB::table('formulaire_champ')->where('formulaire', 'eval_site')->where('cle', 'id_type_eval_site')->value('ordre') ?? 30;

        if (! DB::table('formulaire_champ')->where('formulaire', 'eval_site')->where('cle', 'entite_client')->exists()) {
            DB::table('formulaire_champ')->insert([
                'formulaire' => 'eval_site',
                'cle' => 'entite_client',
                'libelle' => 'Entité du client',
                'type' => 'boutons',
                'standard' => true,
                'stockage' => 'aucun',
                'section' => 'Général',
                'ordre' => $ordreType - 5,
                'visible' => true,
                'obligatoire' => false,
                'verrouille' => false,
                'pleine_largeur' => false,
                'type_modifiable' => false,
                'aide' => 'Reprise de la fiche du client. Elle détermine les types d\'évaluation proposés.',
                'defaut' => 'client.entite_id_client',
                'source_liste' => 'entite',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('formulaire_champ')->where('formulaire', 'eval_site')->where('cle', 'id_type_eval_site')->update([
            'liste_parent' => 'entite_client',
            'liste_parent_colonne' => 'id_entite',
            'aide' => 'Selon l\'entité du client ; coché automatiquement s\'il n\'y a qu\'un type possible.',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('formulaire_champ')->where('formulaire', 'eval_site')->where('cle', 'id_type_eval_site')
            ->update(['liste_parent' => null, 'liste_parent_colonne' => null]);
        DB::table('formulaire_champ')->where('formulaire', 'eval_site')->where('cle', 'entite_client')->delete();

        Schema::table('type_eval_site', function (Blueprint $table) {
            $table->dropColumn('id_entite');
        });
    }
};
