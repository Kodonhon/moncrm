<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TESTS UNIQUEMENT (base SQLite) : même colonne que database/sql/produit.sql. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produit', function (Blueprint $table) {
            $table->decimal('montant_com_commer', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('produit', function (Blueprint $table) {
            $table->dropColumn('montant_com_commer');
        });
    }
};
