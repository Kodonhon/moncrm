<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Préparation installation : table prep_inst (appels du CX), même structure que la table d'origine
 * + rattachement au client du CRM. Idempotent : ne fait rien si la table existe déjà.
 * Équivalent de database/sql/prep_inst.sql. Historique : php artisan crm:importer-prep-inst
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('credit_scoring')) {
            throw new RuntimeException('La table credit_scoring est absente : exécutez d\'abord database/sql/credit_scoring.sql.');
        }

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS prep_inst (
                id_prep                 varchar(20)  PRIMARY KEY,
                idcx_appsheet           varchar(30),
                date_prep               date         NOT NULL DEFAULT current_date,
                heure_prep              time(0),
                id_agent_traitant       integer,
                id_prospect             varchar(20),
                id_client               varchar(20)  REFERENCES client (id_client),
                id_cs                   varchar(20)  REFERENCES credit_scoring (id_cs),
                id_type_req             smallint     DEFAULT 1,
                nom_prenom              varchar(200),
                cni_client              varchar(50),
                id_agent_vente          integer,
                contact1                varchar(30),
                contact2                varchar(30),
                ville                   varchar(100),
                localite                varchar(100),
                id_localite             integer,
                id_produit              integer,
                id_src_eau              integer,
                tdh_correct             smallint,
                client_reservoir        smallint,
                client_supp_reserv      smallint,
                compatibilite_pompe     smallint,
                resul_prep_inst         smallint,
                si_pas_pret_raison      text,
                statut_prepa_inst       smallint,
                commentaire_prepa_inst  text,
                visibilite              smallint     DEFAULT 1,
                last_modified_by        varchar(150),
                num_contrat_upya        varchar(30),
                created_at              timestamp(0) NULL,
                updated_at              timestamp(0) NULL
            )
            SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS prep_inst_id_client_date_index ON prep_inst (id_client, date_prep DESC, heure_prep DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS prep_inst_cni_index ON prep_inst (cni_client)');
        DB::statement('CREATE INDEX IF NOT EXISTS prep_inst_id_prospect_index ON prep_inst (id_prospect)');

        Cache::forget('table-prep-inst-disponible');
    }

    /** Ne supprime rien : la table contient l'historique des appels du CX. */
    public function down(): void
    {
    }
};
