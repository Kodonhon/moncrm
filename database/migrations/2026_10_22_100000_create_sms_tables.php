<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages SMS (envoi par l'API LeTexto) : configuration de l'API, modèles avec circuit d'approbation,
 * historique de chaque SMS envoyé. Uniquement des créations de tables : rien n'est modifié ni supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sms_configuration')) {
            Schema::create('sms_configuration', function (Blueprint $table) {
                $table->id();
                $table->string('fournisseur', 30)->default('letexto');
                $table->string('url_base')->default('https://apis.letexto.com/v1');
                $table->string('chemin_envoi')->default('/messages/send');
                $table->string('methode', 6)->default('GET');
                $table->string('mode_auth', 10)->default('query'); // query : ?token=… ; bearer : en-tête Authorization
                $table->text('token')->nullable(); // chiffré par Laravel (APP_KEY)
                $table->string('expediteur', 11)->nullable(); // nom d'expéditeur validé chez LeTexto (11 caractères max)
                $table->string('indicatif', 5)->default('225');
                $table->string('param_token', 30)->default('token');
                $table->string('param_expediteur', 30)->default('from');
                $table->string('param_destinataire', 30)->default('to');
                $table->string('param_message', 30)->default('content');
                $table->string('url_retour')->nullable(); // accusés de réception (si LeTexto le propose)
                $table->boolean('actif')->default(false);
                $table->boolean('mode_test')->default(true); // simule l'envoi sans appeler l'API
                $table->string('numero_test', 20)->nullable();
                $table->unsignedInteger('limite_par_envoi')->default(500);
                $table->foreignId('modifie_par')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sms_modeles')) {
            Schema::create('sms_modeles', function (Blueprint $table) {
                $table->id();
                $table->string('nom');
                $table->string('categorie', 30)->nullable();
                $table->unsignedBigInteger('id_entite')->nullable();
                $table->text('contenu');
                $table->string('approbation', 20)->default('brouillon'); // brouillon, en_attente, approuve, rejete
                $table->boolean('actif')->default(true);
                $table->foreignId('cree_par')->nullable();
                $table->foreignId('approuve_par')->nullable();
                $table->timestamp('approuve_le')->nullable();
                $table->text('motif_rejet')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sms_messages')) {
            Schema::create('sms_messages', function (Blueprint $table) {
                $table->id();
                $table->uuid('lot')->nullable()->index(); // un envoi groupé = un lot
                $table->foreignId('sms_modele_id')->nullable()->index();
                $table->string('id_client', 40)->nullable()->index();
                $table->string('telephone', 20);
                $table->text('contenu');
                $table->unsignedSmallInteger('nb_sms')->default(1);
                $table->string('statut', 15)->default('en_attente'); // en_attente, envoye, echec, simule
                $table->unsignedSmallInteger('code_http')->nullable();
                $table->text('reponse_api')->nullable();
                $table->foreignId('envoye_par')->nullable();
                $table->timestamp('envoye_le')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
    }
};
