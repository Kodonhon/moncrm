<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications du CRM (cloche en haut à droite) et photo de profil (page « Mon profil » de Breezy).
 * Uniquement des ajouts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Table standard des notifications Laravel, utilisée par les notifications Filament en base
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('users', 'avatar_url')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('avatar_url')->nullable();
            });
        }
    }

    public function down(): void
    {
    }
};
