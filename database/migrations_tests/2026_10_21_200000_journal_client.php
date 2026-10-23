<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TESTS UNIQUEMENT (base SQLite) : même table que database/sql/journal_client.sql. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_client', function (Blueprint $table) {
            $table->id();
            $table->string('id_client', 40)->index();
            $table->string('objet', 40);
            $table->string('objet_id', 40)->nullable();
            $table->string('action', 20);
            $table->json('changements')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('nom_utilisateur', 150)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_client');
    }
};
