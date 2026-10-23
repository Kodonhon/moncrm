-- =====================================================================================================
-- CRM GREENO — Installations (rapports d'installation des techniciens, export AppSheet « ta_installation »)
-- =====================================================================================================
-- À relire puis exécuter à la main (Supabase > SQL Editor ou pgAdmin). Idempotent. Rien n'est supprimé.
-- Un enregistrement par rapport d'installation. Un client dont un rapport indique « système installé = OUI »
-- sort de toutes les listes en attente d'action (validation crédit, préparation installation, assignation des kits).
-- Données : php artisan crm:importer-installations "<fichier AppSheet .csv>"
-- =====================================================================================================

CREATE TABLE IF NOT EXISTS installation (
    id_installation          varchar(20)  PRIMARY KEY,                      -- « ID installation » d'AppSheet
    id_client                varchar(20)  REFERENCES client (id_client),   -- vide : client introuvable dans le CRM
    date_installation        date,
    systeme_installe         smallint,                                      -- 1 = OUI, 0 = NON
    kit_recu                 smallint,                                      -- 1 = OUI, 0 = NON
    num_cni                  varchar(50),
    num_contrat              varchar(30),
    num_contrat_upya         varchar(30),
    type_installation        varchar(30),
    type_produit             varchar(150),
    type_paiement            varchar(20),
    contact_client           varchar(30),
    ville                    varchar(100),
    localite                 varchar(150),
    geolocalisation          varchar(60),
    prise_en_charge_par      varchar(150),
    assignee_a               varchar(150),
    statut_technicien        varchar(30),
    code_controleur          varchar(60),
    num_serie_pompe          varchar(60),
    num_serie_panneau_1      varchar(60),
    num_serie_panneau_2      varchar(60),
    raison_non_installation  text,
    cause_non_installation   text,
    donnees_origine          json,                                          -- toutes les colonnes de l'export (photos, contrôles…)
    created_at               timestamp(0) NULL,
    updated_at               timestamp(0) NULL
);

CREATE INDEX IF NOT EXISTS installation_id_client_index ON installation (id_client, systeme_installe);

-- Contrôle : la table existe
SELECT to_regclass('public.installation') IS NOT NULL AS installation_ok;
