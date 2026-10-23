-- =====================================================================================================
-- CRM GREENO — Table agent complétée (colonnes du fichier RH + nom complet calculé)
-- =====================================================================================================
-- Équivalent SQL de database/migrations/2026_10_20_100000_completer_table_agent.php, à exécuter à la main
-- (pgAdmin ou Supabase > SQL Editor) au lieu de « php artisan migrate ».
-- Script idempotent : ADD COLUMN IF NOT EXISTS, index IF NOT EXISTS. Rien n'est supprimé ni renommé.
-- Ensuite, les agents du fichier RH se reprennent avec : php artisan crm:importer-agents
-- =====================================================================================================

ALTER TABLE agent ADD COLUMN IF NOT EXISTS id_fonction_agent    integer;
ALTER TABLE agent ADD COLUMN IF NOT EXISTS id_type_agent        integer;
ALTER TABLE agent ADD COLUMN IF NOT EXISTS id_zone_agent        integer;
ALTER TABLE agent ADD COLUMN IF NOT EXISTS id_dpt               integer;
ALTER TABLE agent ADD COLUMN IF NOT EXISTS id_int               integer;
ALTER TABLE agent ADD COLUMN IF NOT EXISTS id_entite            integer;
ALTER TABLE agent ADD COLUMN IF NOT EXISTS sup_agent_id         integer;
ALTER TABLE agent ADD COLUMN IF NOT EXISTS prenoms_agent        varchar(150);
ALTER TABLE agent ADD COLUMN IF NOT EXISTS genre_agent          smallint;
ALTER TABLE agent ADD COLUMN IF NOT EXISTS date_entree_agent    date;
ALTER TABLE agent ADD COLUMN IF NOT EXISTS date_elearning_agent date;
ALTER TABLE agent ADD COLUMN IF NOT EXISTS date_sortie_agent    date;
ALTER TABLE agent ADD COLUMN IF NOT EXISTS photo_agent          varchar(150);
ALTER TABLE agent ADD COLUMN IF NOT EXISTS email_agent          varchar(150);

CREATE INDEX IF NOT EXISTS agent_sup_agent_id_index ON agent (sup_agent_id);
CREATE INDEX IF NOT EXISTS agent_id_entite_index    ON agent (id_entite);

-- Nom complet « NOM Prénoms », calculé par PostgreSQL (utilisé dans toutes les listes de choix)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'agent' AND column_name = 'nom_complet_agent') THEN
        ALTER TABLE agent ADD COLUMN nom_complet_agent varchar(200)
            GENERATED ALWAYS AS (btrim(coalesce(nom_agent, '') || ' ' || coalesce(prenoms_agent, ''))) STORED;
    END IF;
END $$;

-- Contrôle : doit renvoyer 15 lignes
SELECT column_name FROM information_schema.columns
WHERE table_name = 'agent' AND column_name IN (
    'id_fonction_agent', 'id_type_agent', 'id_zone_agent', 'id_dpt', 'id_int', 'id_entite', 'sup_agent_id', 'prenoms_agent',
    'genre_agent', 'date_entree_agent', 'date_elearning_agent', 'date_sortie_agent', 'photo_agent', 'email_agent', 'nom_complet_agent');
