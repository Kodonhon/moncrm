-- =====================================================================================================
-- CRM GREENO — Libellés de « Statut du CDS 2 » (q42_cs), à la place de « Code 2 », « Code 3 »…
-- =====================================================================================================
-- À exécuter dans le SQL Editor de Supabase. Idempotent. Ne modifie que la liste des choix de la question ;
-- les réponses déjà enregistrées (codes 1 à 8) ne changent pas.
-- Libellés retrouvés dans les exports AppSheet, en rapprochant chaque scoring par son ID.
-- =====================================================================================================

UPDATE formulaire_champ
SET options = '[
        {"valeur":"1","libelle":"Validé","parent":null},
        {"valeur":"2","libelle":"Invalidé","parent":null},
        {"valeur":"3","libelle":"Appel non répondu","parent":null},
        {"valeur":"4","libelle":"En cours de traitement","parent":null},
        {"valeur":"5","libelle":"En attente de mise à jour","parent":null},
        {"valeur":"6","libelle":"En cours","parent":null},
        {"valeur":"7","libelle":"En cours de traitement (2)","parent":null},
        {"valeur":"8","libelle":"Injoignable","parent":null}
    ]',
    updated_at = now()
WHERE formulaire = 'credit_scoring' AND cle = 'q42_cs';

-- Contrôle
SELECT cle, options FROM formulaire_champ WHERE formulaire = 'credit_scoring' AND cle = 'q42_cs';
