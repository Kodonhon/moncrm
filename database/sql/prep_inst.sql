-- =====================================================================================================
-- CRM GREENO — Préparation installation (appels du CX) : table prep_inst
-- =====================================================================================================
-- À exécuter dans le SQL Editor de Supabase (ou pgAdmin). Idempotent. Rien n'est supprimé ni renommé.
-- Prérequis : database/sql/credit_scoring.sql déjà exécuté.
--
-- Mêmes colonnes que la table d'origine (AppSheet « prep_inst »), plus le rattachement au client du CRM.
-- Un enregistrement par appel du CX ; le plus récent donne l'état du dossier :
--   resul_prep_inst : 1 injoignable · 2 prêt pour l'installation · 3 déjà installé · 4 souhaite résilier
--                     5 contrat déjà résilié · 6 ne décroche pas · 7 colonne d'eau incompatible
--                     8 volume d'eau insuffisant · 9 pas prêt · 10 souhaite annuler · 11 parent proche joint
--                     12 pas de source d'eau
-- Historique : php artisan crm:importer-prep-inst (fichier storage/app/imports/prep_inst.csv)
-- =====================================================================================================

CREATE TABLE IF NOT EXISTS prep_inst (
    id_prep                 varchar(20)  PRIMARY KEY,
    idcx_appsheet           varchar(30),
    date_prep               date         NOT NULL DEFAULT current_date,
    heure_prep              time(0),
    id_agent_traitant       integer,                                     -- agent CX (pas de clé étrangère : l'historique contient des agents absents)
    id_prospect             varchar(20),                                 -- identifiant du système d'origine
    id_client               varchar(20)  REFERENCES client (id_client),  -- client du CRM (vide si introuvable)
    id_cs                   varchar(20)  REFERENCES credit_scoring (id_cs),
    id_type_req             smallint     DEFAULT 1,                      -- 1 = préparation installation
    nom_prenom              varchar(200),
    cni_client              varchar(50),
    id_agent_vente          integer,
    contact1                varchar(30),
    contact2                varchar(30),
    ville                   varchar(100),
    localite                varchar(100),
    id_localite             integer,
    id_produit              integer,                                     -- produit (pompe), repris du TDH
    id_src_eau              integer,                                     -- 1 Forage · 2 Lac/Rivière/Fleuve · 3 Puits · 4 Barrage · 5 Bas fond · 6 Fleuve · 7 Lac · 8 Rivière
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
);

CREATE INDEX IF NOT EXISTS prep_inst_id_client_date_index ON prep_inst (id_client, date_prep DESC, heure_prep DESC);
CREATE INDEX IF NOT EXISTS prep_inst_cni_index ON prep_inst (cni_client);
CREATE INDEX IF NOT EXISTS prep_inst_id_prospect_index ON prep_inst (id_prospect);

-- Contrôle
SELECT to_regclass('public.prep_inst') IS NOT NULL AS prep_inst_ok, (SELECT count(*) FROM prep_inst) AS appels;
