<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table agent complétée avec les colonnes du fichier RH d'origine (fonction, type, zone, département,
 * entité, supérieur, prénoms, genre, dates, e-mail…), et un nom complet calculé par PostgreSQL
 * (« NOM Prénoms ») utilisé dans toutes les listes de choix.
 * Les données sont reprises ensuite avec : php artisan crm:importer-agents
 */
return new class extends Migration
{
    private const COLONNES = [
        'id_fonction_agent' => 'integer', 'id_type_agent' => 'integer', 'id_zone_agent' => 'integer',
        'id_dpt' => 'integer', 'id_int' => 'integer', 'id_entite' => 'integer', 'sup_agent_id' => 'integer',
        'prenoms_agent' => 'string', 'genre_agent' => 'smallInteger',
        'date_entree_agent' => 'date', 'date_elearning_agent' => 'date', 'date_sortie_agent' => 'date',
        'photo_agent' => 'string', 'email_agent' => 'string',
    ];

    public function up(): void
    {
        Schema::table('agent', function (Blueprint $table) {
            foreach (self::COLONNES as $colonne => $type) {
                if (! Schema::hasColumn('agent', $colonne)) {
                    match ($type) {
                        'string' => $table->string($colonne, 150)->nullable(),
                        default => $table->{$type}($colonne)->nullable(),
                    };
                }
            }
        });

        Schema::table('agent', function (Blueprint $table) {
            $table->index('sup_agent_id');
            $table->index('id_entite');
        });

        if (DB::getDriverName() === 'pgsql' && ! Schema::hasColumn('agent', 'nom_complet_agent')) {
            DB::statement("ALTER TABLE agent ADD COLUMN nom_complet_agent varchar(200)
                GENERATED ALWAYS AS (btrim(coalesce(nom_agent, '') || ' ' || coalesce(prenoms_agent, ''))) STORED");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agent', 'nom_complet_agent')) {
            DB::statement('ALTER TABLE agent DROP COLUMN nom_complet_agent');
        }

        Schema::table('agent', function (Blueprint $table) {
            $table->dropIndex(['sup_agent_id']);
            $table->dropIndex(['id_entite']);
            $table->dropColumn(array_keys(self::COLONNES));
        });
    }
};
