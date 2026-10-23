-- =====================================================================================================
-- CRM GREENO — Catalogue des produits : commission du commercial
-- =====================================================================================================
-- À relire puis exécuter à la main (Supabase > SQL Editor ou pgAdmin). Idempotent. Rien n'est supprimé.
-- Les produits eux-mêmes (fichier produit.csv) se chargent ensuite avec :
--   php artisan crm:importer --seulement=produit
-- =====================================================================================================

-- Montant de la commission versée au commercial pour la vente du kit (FCFA)
ALTER TABLE produit ADD COLUMN IF NOT EXISTS montant_com_commer numeric(14, 2);

-- Contrôle
SELECT column_name, data_type FROM information_schema.columns
WHERE table_name = 'produit' ORDER BY ordinal_position;
