<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Questions « Calcul » : formule paramétrable (Paramètres > Formulaire …).
 * - Champ TDH de l'évaluation du site, calculé à partir des mesures du pompage.
 * - Une évaluation (un produit) garde la trace du KYC utilisé : le même KYC sert à plusieurs produits
 *   tant que la pièce n'est pas expirée.
 */
return new class extends Migration
{
    /** Formule par défaut, à ajuster dans Paramètres : hauteur géométrique + pertes de charge estimées. */
    private const FORMULE_TDH = '{prof_reglage_pomp_tdh} + {haut_elevation_verticale_tdh} + {haut_reservoir_tdh} + {haut_supp_reservoir_tdh}'
        .' + 0,1 * {dist_src_eau_et_reservoir_tdh} + 0,5 * {nb_coude_connecteur}';

    public function up(): void
    {
        Schema::table('formulaire_champ', function (Blueprint $table) {
            $table->string('formule', 1000)->nullable();
        });

        Schema::table('eval_site', function (Blueprint $table) {
            $table->string('id_kyc', 20)->nullable()->index();
        });

        $ordre = DB::table('formulaire_champ')->where('formulaire', 'eval_site')->where('cle', 'reommandation_tdh')->value('ordre') ?? 500;

        if (! DB::table('formulaire_champ')->where('formulaire', 'eval_site')->where('cle', 'tdh')->exists()) {
            DB::table('formulaire_champ')->insert([
                'formulaire' => 'eval_site',
                'cle' => 'tdh',
                'libelle' => 'TDH : hauteur manométrique totale (m)',
                'type' => 'calcul',
                'standard' => true,
                'stockage' => 'colonne',
                'section' => 'Pompage solaire (TDH)',
                'ordre' => $ordre - 15,
                'visible' => true,
                'obligatoire' => false,
                'verrouille' => false,
                'pleine_largeur' => true,
                'type_modifiable' => false,
                'aide' => 'Calculé automatiquement à partir des mesures ci-dessus (formule modifiable dans Paramètres).',
                'formule' => self::FORMULE_TDH,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('formulaire_champ')->where('formulaire', 'eval_site')->where('cle', 'tdh')->delete();

        Schema::table('eval_site', function (Blueprint $table) {
            $table->dropColumn('id_kyc');
        });

        Schema::table('formulaire_champ', function (Blueprint $table) {
            $table->dropColumn('formule');
        });
    }
};
