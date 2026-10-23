-- =====================================================================================================
-- CRM GREENO — N° de contrat UPYA sur le contrat du CRM, deposit repris automatiquement au scoring
-- =====================================================================================================
-- À exécuter dans le SQL Editor de Supabase. Idempotent. N'efface ni ne renomme rien.
--
-- Un dossier ne passe au crédit que si un contrat UPYA du client a un deposit complet (full_deposit)
-- dans la base des ventes (Railway, vue crm_solaire.paiement_contrat). Le lien se fait par le n° de
-- contrat UPYA, désormais saisi par l'agent dans le contrat du CRM.
-- =====================================================================================================

BEGIN;

-- 1. N° de contrat UPYA du contrat (7 à 9 chiffres), un seul contrat CRM par n° UPYA
ALTER TABLE contrat ADD COLUMN IF NOT EXISTS num_contrat_upya varchar(30);

CREATE UNIQUE INDEX IF NOT EXISTS contrat_num_contrat_upya_unique
    ON contrat (num_contrat_upya)
    WHERE num_contrat_upya IS NOT NULL;

COMMENT ON COLUMN contrat.num_contrat_upya IS
    'N° du contrat dans UPYA : relie le contrat à son deposit (base des ventes, crm_solaire.paiement_contrat).';

-- 2. Credit scoring : le n° UPYA est repris du contrat (et non plus de la « Référence UPYA » du KYC,
--    qui est une référence client CI00…), et la question du dépôt initial n'est plus posée.
UPDATE formulaire_champ
SET defaut = 'client.num_contrat_upya_actuel',
    aide = 'Repris du contrat du client. À corriger dans le contrat si besoin.',
    updated_at = now()
WHERE formulaire = 'credit_scoring' AND cle = 'num_contrat_upya';

UPDATE formulaire_champ
SET visible = false, updated_at = now()
WHERE formulaire = 'credit_scoring' AND cle = 'q11_cs';

COMMIT;

-- Contrôle
SELECT column_name, data_type FROM information_schema.columns
WHERE table_name = 'contrat' AND column_name = 'num_contrat_upya';
SELECT cle, defaut, visible FROM formulaire_champ WHERE formulaire = 'credit_scoring' AND cle IN ('num_contrat_upya', 'q11_cs');
