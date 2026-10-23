<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TESTS UNIQUEMENT (base SQLite) : nom complet de l'agent, calculé comme la colonne générée
 * créée par PostgreSQL (migration completer_table_agent), qui n'est pas ajoutée en SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agent', 'nom_complet_agent')) {
            return;
        }

        Schema::table('agent', function (Blueprint $table) {
            $table->string('nom_complet_agent', 200)->nullable()
                ->virtualAs("trim(coalesce(nom_agent, '') || ' ' || coalesce(prenoms_agent, ''))");
        });
    }

    public function down(): void
    {
        Schema::table('agent', function (Blueprint $table) {
            $table->dropColumn('nom_complet_agent');
        });
    }
};
