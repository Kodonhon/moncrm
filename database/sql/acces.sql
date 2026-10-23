-- =====================================================================================================
-- CRM GREENO — Rôles et accès (utilisateurs, rôles, permissions, périmètre des données)
-- =====================================================================================================
-- Équivalent SQL de database/migrations/2026_10_20_130000_create_acces_tables.php, à exécuter à la main
-- (Supabase > SQL Editor ou pgAdmin) au lieu de « php artisan migrate ». Idempotent. Rien n'est supprimé.
--
-- Ensuite : php artisan crm:installer-acces <email de l'administrateur>
--   → crée les rôles par défaut (Administrateur, Direction, Responsable commercial, Commercial terrain,
--     Agent crédit, Agent CX, Shop manager) et le premier administrateur ;
--   → les comptes existants sans rôle deviennent administrateurs (personne n'est bloqué).
-- Tant que ce script n'a pas été exécuté, tout est permis : le CRM fonctionne comme avant.
-- =====================================================================================================

-- Rôles : périmètre = tout | entite | shop | equipe | personnel ; « systeme » = rôle protégé (administrateur)
CREATE TABLE IF NOT EXISTS crm_roles (
    id           bigserial    PRIMARY KEY,
    code         varchar(50)  NOT NULL UNIQUE,
    nom          varchar(100) NOT NULL,
    description  varchar(255),
    couleur      varchar(20)  NOT NULL DEFAULT 'violet',
    perimetre    varchar(20)  NOT NULL DEFAULT 'personnel',
    systeme      boolean      NOT NULL DEFAULT false,
    created_at   timestamp(0) NULL,
    updated_at   timestamp(0) NULL
);

-- Permissions d'un rôle : « module.action » (ex. clients.voir, kyc.valider)
CREATE TABLE IF NOT EXISTS crm_role_permissions (
    id          bigserial   PRIMARY KEY,
    role_id     bigint      NOT NULL REFERENCES crm_roles (id) ON DELETE CASCADE,
    permission  varchar(80) NOT NULL,
    CONSTRAINT crm_role_permissions_role_id_permission_unique UNIQUE (role_id, permission)
);

-- Rôles de chaque utilisateur
CREATE TABLE IF NOT EXISTS crm_role_utilisateur (
    user_id     bigint       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    role_id     bigint       NOT NULL REFERENCES crm_roles (id) ON DELETE CASCADE,
    created_at  timestamp(0) NULL,
    PRIMARY KEY (user_id, role_id)
);

-- Compte utilisateur : agent rattaché (périmètres « ses clients », « son équipe », « son shop »),
-- compte actif, entités autorisées, dernière connexion
ALTER TABLE users ADD COLUMN IF NOT EXISTS id_agent           integer;
ALTER TABLE users ADD COLUMN IF NOT EXISTS actif              boolean NOT NULL DEFAULT true;
ALTER TABLE users ADD COLUMN IF NOT EXISTS entites_autorisees json;
ALTER TABLE users ADD COLUMN IF NOT EXISTS derniere_connexion timestamp(0);
CREATE INDEX IF NOT EXISTS users_id_agent_index ON users (id_agent);

-- Contrôle : 3 tables
SELECT count(*) AS tables_acces
FROM information_schema.tables
WHERE table_schema = 'public' AND table_name IN ('crm_roles', 'crm_role_permissions', 'crm_role_utilisateur');
