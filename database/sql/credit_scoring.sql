-- =====================================================================================================
-- CRM GREENO — Validation crédit : validation du KYC et credit scoring
-- =====================================================================================================
-- À relire puis exécuter à la main (pgAdmin ou psql), en une seule fois, sur la base Supabase.
-- Script idempotent : il peut être relancé sans risque (IF NOT EXISTS, contrôles).
-- Indépendant de database/sql/contrat_regles.sql (ordre d'exécution libre).
--
-- Parcours :
--   • l'agent terrain fait le KYC et au moins un dimensionnement → le dossier arrive au crédit ;
--   • le contrat peut être créé avant la validation du KYC par le crédit ;
--   • un agent crédit (agent.role_agent = 'credit') valide le KYC ou le renvoie « à corriger » (avec motif) ;
--   • le KYC corrigé par l'agent terrain repasse « à valider » ;
--   • l'agent crédit fait le credit scoring (un enregistrement par appel / tentative).
--
-- Ordre : 0. contrôles → 1. rôle des agents → 2. validation du KYC → 3. table credit_scoring
--         → 4. contrôle final.
-- Rien n'est supprimé ni renommé. Les données (questions du formulaire, historique credit_scoring.csv)
-- se chargent ensuite avec : php artisan crm:credit-scoring
-- =====================================================================================================


-- -----------------------------------------------------------------------------------------------------
-- 0. CONTRÔLES PRÉALABLES (lecture seule)
-- -----------------------------------------------------------------------------------------------------
SELECT
    (SELECT count(*) FROM agent)                                   AS agents,
    (SELECT count(*) FROM kyc WHERE visibilite_kyc = 1)            AS kyc_actifs,
    to_regclass('public.credit_scoring') IS NOT NULL               AS credit_scoring_existe;


-- -----------------------------------------------------------------------------------------------------
-- 1. RÔLE DES AGENTS : terrain (par défaut) ou crédit
-- -----------------------------------------------------------------------------------------------------
ALTER TABLE agent ADD COLUMN IF NOT EXISTS role_agent varchar(20) NOT NULL DEFAULT 'terrain';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'agent_role_agent_check') THEN
        ALTER TABLE agent ADD CONSTRAINT agent_role_agent_check CHECK (role_agent IN ('terrain', 'credit'));
    END IF;
END $$;


-- -----------------------------------------------------------------------------------------------------
-- 2. VALIDATION DU KYC PAR LE CRÉDIT
--    statut_validation_kyc : 0 = à valider, 1 = validé, 2 = à corriger (renvoyé à l'agent terrain)
--    Les KYC existants partent « à valider » ; la commande d'import marque « validés » ceux dont
--    le dernier credit scoring de l'historique a validé la commande.
-- -----------------------------------------------------------------------------------------------------
ALTER TABLE kyc ADD COLUMN IF NOT EXISTS statut_validation_kyc   smallint     NOT NULL DEFAULT 0;
ALTER TABLE kyc ADD COLUMN IF NOT EXISTS motif_validation_kyc    text;
ALTER TABLE kyc ADD COLUMN IF NOT EXISTS date_validation_kyc     timestamp(0);
ALTER TABLE kyc ADD COLUMN IF NOT EXISTS id_agent_validation_kyc integer;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'kyc_statut_validation_kyc_check') THEN
        ALTER TABLE kyc ADD CONSTRAINT kyc_statut_validation_kyc_check CHECK (statut_validation_kyc IN (0, 1, 2));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'kyc_id_agent_validation_kyc_foreign') THEN
        ALTER TABLE kyc ADD CONSTRAINT kyc_id_agent_validation_kyc_foreign
            FOREIGN KEY (id_agent_validation_kyc) REFERENCES agent (id_agent);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS kyc_statut_validation_kyc_index ON kyc (statut_validation_kyc) WHERE visibilite_kyc = 1;


-- -----------------------------------------------------------------------------------------------------
-- 3. TABLE CREDIT_SCORING : mêmes colonnes que le système d'origine (credit_scoring.csv),
--    plus le rattachement au client et au KYC du CRM.
--    Les réponses q1_cs … q47_cs sont en texte : leur type de saisie se règle dans
--    Paramètres > Formulaire credit scoring.
-- -----------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS credit_scoring (
    id_cs                 varchar(20)  PRIMARY KEY,
    id_client             varchar(20)  REFERENCES client (id_client),   -- vide : prospect de l'historique introuvable
    id_kyc                varchar(20)  REFERENCES kyc (id_kyc),
    id_activite           integer,
    num_cni               varchar(50),
    id_src_eau            integer,
    id_periodicite        integer,
    id_prospect           varchar(20),                                  -- identifiant du système d'origine
    id_agent              integer      REFERENCES agent (id_agent),     -- agent crédit
    date_cs               date,
    heure_cs              time(0),
    q1_cs  text, q2_cs  text, q3_cs  text, q4_cs  text, q5_cs  text, q6_cs  text, q7_cs  text, q8_cs  text,
    q9_cs  text, q10_cs text, q11_cs text, q12_cs text, q13_cs text, q14_cs text, q15_cs text, q15_cs_autre text,
    q16_cs text, q17_cs text, q18_cs text, q19_cs text, q20_cs text, q21_cs text, q22_cs text, q23_cs text,
    q24_cs text, q25_cs text, q26_cs text, q27_cs text, q28_cs text, q28_cs_autre text, q29_cs text, q30_cs text,
    q31_cs text, q32_cs text, q33_cs text, q34_cs text, q35_cs text, q36_cs text, q37_cs text, q38_cs text,
    q39_cs text, q40_cs text, q41_cs text, q42_cs text, q43_cs text, q44_cs text, q45_cs text, q46_cs text,
    q47_cs text,
    id_prospect_appsheet  varchar(30),
    visibilite_cs         smallint     DEFAULT 1,
    last_modified_by      varchar(150),
    num_contrat_upya      varchar(30),
    donnees_formulaire    json,                                         -- réponses aux questions ajoutées dans Paramètres
    created_at            timestamp(0) NULL,
    updated_at            timestamp(0) NULL
);

CREATE INDEX IF NOT EXISTS credit_scoring_id_client_index ON credit_scoring (id_client, date_cs DESC);
CREATE INDEX IF NOT EXISTS credit_scoring_num_cni_index   ON credit_scoring (num_cni);


-- -----------------------------------------------------------------------------------------------------
-- 4. CONTRÔLE FINAL
-- -----------------------------------------------------------------------------------------------------
SELECT
    (SELECT count(*) FROM information_schema.columns WHERE table_name = 'agent' AND column_name = 'role_agent')             AS role_agent_ok,
    (SELECT count(*) FROM information_schema.columns WHERE table_name = 'kyc'   AND column_name = 'statut_validation_kyc')  AS validation_kyc_ok,
    to_regclass('public.credit_scoring') IS NOT NULL                                                                        AS credit_scoring_ok;
