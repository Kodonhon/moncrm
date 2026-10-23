-- =====================================================================================================
-- CRM GREENO — Messages SMS (LeTexto) et notifications (cloche)
-- =====================================================================================================
-- Équivalent SQL des migrations 2026_10_21_100000_notifications_et_profil et 2026_10_22_100000_create_sms_tables
-- (php artisan migrate n'est jamais lancé sur cette base). Idempotent. Uniquement des créations :
-- trois tables SMS, la table des notifications et une colonne facultative avatar_url sur users.
-- Rien n'est modifié ni supprimé.
-- =====================================================================================================

BEGIN;

-- 1. Notifications du CRM (cloche en haut à droite), table standard de Laravel
CREATE TABLE IF NOT EXISTS notifications (
    id               uuid         PRIMARY KEY,
    type             varchar(255) NOT NULL,
    notifiable_type  varchar(255) NOT NULL,
    notifiable_id    bigint       NOT NULL,
    data             jsonb        NOT NULL,   -- jsonb (et non text) : Filament lit data->>'format' sous PostgreSQL
    read_at          timestamp(0) NULL,
    created_at       timestamp(0) NULL,
    updated_at       timestamp(0) NULL
);
CREATE INDEX IF NOT EXISTS notifications_notifiable_type_notifiable_id_index ON notifications (notifiable_type, notifiable_id);

-- Correctif : table créée d'abord avec data en text ; passée en jsonb (table encore vide, sans perte)
ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb;

-- Photo de profil (page « Mon profil », facultative)
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_url varchar(255) NULL;

-- 2. Configuration de l'API SMS (une ligne) ; le token est chiffré par Laravel (APP_KEY)
CREATE TABLE IF NOT EXISTS sms_configuration (
    id                  bigserial    PRIMARY KEY,
    fournisseur         varchar(30)  NOT NULL DEFAULT 'letexto',
    url_base            varchar(255) NOT NULL DEFAULT 'https://apis.letexto.com/v1',
    chemin_envoi        varchar(255) NOT NULL DEFAULT '/messages/send',
    methode             varchar(6)   NOT NULL DEFAULT 'GET',
    mode_auth           varchar(10)  NOT NULL DEFAULT 'query',
    token               text         NULL,
    expediteur          varchar(11)  NULL,
    indicatif           varchar(5)   NOT NULL DEFAULT '225',
    param_token         varchar(30)  NOT NULL DEFAULT 'token',
    param_expediteur    varchar(30)  NOT NULL DEFAULT 'from',
    param_destinataire  varchar(30)  NOT NULL DEFAULT 'to',
    param_message       varchar(30)  NOT NULL DEFAULT 'content',
    url_retour          varchar(255) NULL,
    actif               boolean      NOT NULL DEFAULT false,
    mode_test           boolean      NOT NULL DEFAULT true,
    numero_test         varchar(20)  NULL,
    limite_par_envoi    integer      NOT NULL DEFAULT 500,
    modifie_par         bigint       NULL,
    created_at          timestamp(0) NULL,
    updated_at          timestamp(0) NULL
);

-- 3. Modèles de SMS, avec circuit d'approbation (brouillon, en_attente, approuve, rejete)
CREATE TABLE IF NOT EXISTS sms_modeles (
    id            bigserial    PRIMARY KEY,
    nom           varchar(255) NOT NULL,
    categorie     varchar(30)  NULL,
    id_entite     bigint       NULL,
    contenu       text         NOT NULL,
    approbation   varchar(20)  NOT NULL DEFAULT 'brouillon',
    actif         boolean      NOT NULL DEFAULT true,
    cree_par      bigint       NULL,
    approuve_par  bigint       NULL,
    approuve_le   timestamp(0) NULL,
    motif_rejet   text         NULL,
    created_at    timestamp(0) NULL,
    updated_at    timestamp(0) NULL
);

-- 4. Historique : un SMS par ligne (en_attente, envoye, echec, simule)
CREATE TABLE IF NOT EXISTS sms_messages (
    id             bigserial    PRIMARY KEY,
    lot            uuid         NULL,
    sms_modele_id  bigint       NULL,
    id_client      varchar(40)  NULL,
    telephone      varchar(20)  NOT NULL,
    contenu        text         NOT NULL,
    nb_sms         smallint     NOT NULL DEFAULT 1,
    statut         varchar(15)  NOT NULL DEFAULT 'en_attente',
    code_http      smallint     NULL,
    reponse_api    text         NULL,
    envoye_par     bigint       NULL,
    envoye_le      timestamp(0) NULL,
    created_at     timestamp(0) NULL,
    updated_at     timestamp(0) NULL
);
CREATE INDEX IF NOT EXISTS sms_messages_lot_index ON sms_messages (lot);
CREATE INDEX IF NOT EXISTS sms_messages_sms_modele_id_index ON sms_messages (sms_modele_id);
CREATE INDEX IF NOT EXISTS sms_messages_id_client_index ON sms_messages (id_client);

-- 5. Les deux migrations Laravel équivalentes sont marquées comme faites
INSERT INTO migrations (migration, batch)
SELECT m, (SELECT coalesce(max(batch), 0) + 1 FROM migrations)
FROM (VALUES ('2026_10_21_100000_notifications_et_profil'), ('2026_10_22_100000_create_sms_tables')) AS v(m)
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = v.m);

COMMIT;

-- Contrôle
SELECT table_name FROM information_schema.tables
WHERE table_name IN ('notifications', 'sms_configuration', 'sms_modeles', 'sms_messages') ORDER BY table_name;
