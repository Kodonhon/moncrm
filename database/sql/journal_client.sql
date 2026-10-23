-- =====================================================================================================
-- CRM GREENO — Journal de chaque client (onglet « Journal » de la fiche)
-- =====================================================================================================
-- À exécuter dans le SQL Editor de Supabase. Idempotent. Crée une seule table ; rien n'est modifié ni supprimé.
-- Chaque création, modification ou suppression faite dans le CRM sur un client et ses dossiers
-- (KYC, dimensionnements, contrats, credit scorings, préparation installation, installations, SMS)
-- y est inscrite, avec l'utilisateur et le détail « champ : avant → après » (colonne changements).
-- =====================================================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS journal_client (
    id               bigserial    PRIMARY KEY,
    id_client        varchar(40)  NOT NULL,
    objet            varchar(40)  NOT NULL,   -- client, kyc, eval_site, contrat, credit_scoring, prep_inst, installation, sms_messages
    objet_id         varchar(40)  NULL,
    action           varchar(20)  NOT NULL,   -- cree, modifie, supprime
    changements      jsonb        NULL,       -- {"Libellé du champ": ["avant", "après"], …}
    user_id          bigint       NULL,
    nom_utilisateur  varchar(150) NULL,
    created_at       timestamp(0) NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS journal_client_id_client_created_at_index ON journal_client (id_client, created_at DESC);

COMMENT ON TABLE journal_client IS 'Journal des modifications et actions faites dans le CRM sur chaque client et ses dossiers.';

COMMIT;

-- Contrôle
SELECT to_regclass('public.journal_client') IS NOT NULL AS table_creee;
