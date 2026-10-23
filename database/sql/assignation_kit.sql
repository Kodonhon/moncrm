-- =====================================================================================================
-- CRM GREENO — Assignation des kits (shop managers) avant l'installation
-- =====================================================================================================
-- À exécuter dans le SQL Editor de Supabase. Idempotent. Crée une seule table ; rien n'est modifié ni supprimé.
-- Le shop manager assigne un kit à un client « prêt pour l'installation » (dernier appel du CX) en notant
-- les numéros de série du contrôleur, de la pompe et des deux panneaux. Le client passe alors dans
-- l'onglet « Prêts pour l'installation », où le technicien le retrouve.
-- =====================================================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS assignation_kit (
    id                     bigserial    PRIMARY KEY,
    id_client              varchar(40)  NOT NULL,
    id_cs                  varchar(40)  NULL,          -- credit scoring validé (dossier)
    num_contrat_upya       varchar(30)  NULL,
    num_serie_controleur   varchar(60)  NOT NULL,
    num_serie_pompe        varchar(60)  NOT NULL,
    num_serie_panneau_1    varchar(60)  NOT NULL,
    num_serie_panneau_2    varchar(60)  NULL,
    id_agent_technicien    integer      NULL,          -- technicien prévu (facultatif)
    date_assignation       date         NOT NULL DEFAULT current_date,
    statut                 varchar(20)  NOT NULL DEFAULT 'assigne',   -- assigne, annule
    commentaire            text         NULL,
    assigne_par            bigint       NULL,          -- utilisateur du CRM
    nom_assigneur          varchar(150) NULL,
    created_at             timestamp(0) NULL,
    updated_at             timestamp(0) NULL
);

CREATE INDEX IF NOT EXISTS assignation_kit_id_client_index ON assignation_kit (id_client);

-- Un même numéro de série de pompe ne peut pas être assigné deux fois (assignations en cours)
CREATE UNIQUE INDEX IF NOT EXISTS assignation_kit_pompe_active_unique
    ON assignation_kit (upper(num_serie_pompe)) WHERE statut = 'assigne';

COMMENT ON TABLE assignation_kit IS 'Kits assignés par les shop managers aux clients prêts pour l''installation (numéros de série).';

COMMIT;

-- Contrôle
SELECT to_regclass('public.assignation_kit') IS NOT NULL AS table_creee;
