-- =====================================================================================================
-- CRM GREENO — Vue des clients en attente de validation crédit : v_clients_attente_credit
-- =====================================================================================================
-- À exécuter dans le SQL Editor de Supabase (ou pgAdmin). Idempotent : CREATE OR REPLACE.
-- Prérequis : database/sql/credit_scoring.sql et database/sql/contrat_regles.sql déjà exécutés,
--             et la migration completer_table_agent (prénoms des agents).
--
-- Un client est « en attente de validation crédit » quand :
--   • le client est actif ;
--   • son KYC actif (le plus récent) est « à valider » (kyc.statut_validation_kyc = 0) ;
--   • il a au moins une évaluation du site (dimensionnement).
-- Les KYC « à corriger » (2) sont chez l'agent terrain, pas au crédit : ils n'y figurent pas.
--
-- À venir : condition « paiement reçu sur le contrat » (vue du système de paiement), à ajouter
-- dans la clause WHERE quand la table locale des paiements existera (voir le bloc commenté en bas).
-- =====================================================================================================

CREATE OR REPLACE VIEW v_clients_attente_credit AS
WITH kyc_actuel AS (
    -- Dernier KYC actif de chaque client
    SELECT DISTINCT ON (k.id_client)
           k.id_kyc, k.id_client, k.date_kyc, k.statut_validation_kyc, k.num_piece_id, k.date_expir_doc,
           k.id_agent AS id_agent_kyc
    FROM kyc k
    WHERE k.visibilite_kyc = 1
    ORDER BY k.id_client, k.date_kyc DESC NULLS LAST, k.id_kyc DESC
),
evaluations AS (
    SELECT e.id_client,
           count(*)                                      AS nb_evaluations,
           count(*) FILTER (WHERE e.statut_eval_site = 1) AS nb_evaluations_validees,
           max(e.date_eval_site)                          AS derniere_evaluation
    FROM eval_site e
    GROUP BY e.id_client
),
contrats AS (
    SELECT c.id_client,
           count(*)                                         AS nb_contrats,
           string_agg(c.num_contrat, ', ' ORDER BY c.date_contrat) AS num_contrats,
           max(c.date_contrat)                              AS dernier_contrat
    FROM contrat c
    WHERE c.statut = 'actif'
    GROUP BY c.id_client
),
scoring AS (
    -- Tentatives de credit scoring déjà faites (appels de l'agent crédit)
    SELECT cs.id_client,
           count(*)       AS nb_tentatives_scoring,
           max(cs.date_cs) AS dernier_scoring
    FROM credit_scoring cs
    WHERE coalesce(cs.visibilite_cs, 1) = 1
    GROUP BY cs.id_client
)
SELECT
    cl.id_client,
    btrim(coalesce(cl.nom_client, '') || ' ' || coalesce(cl.prenoms_client, ''))   AS client,
    cl.contact1_client                                                              AS telephone,
    cl.entite_id_client                                                             AS id_entite,
    en.lib_entite                                                                   AS entite,
    lo.lib_localite                                                                 AS localite,
    ss.lib_site_shop                                                                AS shop,
    btrim(coalesce(ag.nom_agent, '') || ' ' || coalesce(ag.prenoms_agent, ''))      AS agent_terrain,
    ka.id_kyc,
    ka.date_kyc,
    (current_date - ka.date_kyc)                                                    AS jours_attente,
    ka.num_piece_id,
    ka.date_expir_doc,
    (ka.date_expir_doc IS NOT NULL AND ka.date_expir_doc < current_date)           AS piece_expiree,
    ev.nb_evaluations,
    ev.nb_evaluations_validees,
    ev.derniere_evaluation,
    coalesce(co.nb_contrats, 0)                                                     AS nb_contrats,
    co.num_contrats,
    co.dernier_contrat,
    coalesce(sc.nb_tentatives_scoring, 0)                                           AS nb_tentatives_scoring,
    sc.dernier_scoring
FROM client cl
JOIN kyc_actuel ka              ON ka.id_client = cl.id_client
JOIN evaluations ev             ON ev.id_client = cl.id_client
LEFT JOIN contrats co           ON co.id_client = cl.id_client
LEFT JOIN scoring sc            ON sc.id_client = cl.id_client
LEFT JOIN entite en             ON en.id_entite = cl.entite_id_client
LEFT JOIN localite_commune lo   ON lo.id_localite_commune = cl.id_localite_commune
LEFT JOIN site_shop ss          ON ss.id_site_shop = lo.id_site_shop
LEFT JOIN agent ag              ON ag.id_agent = coalesce(cl.id_agent, ka.id_agent_kyc)
WHERE coalesce(cl.visibilite_client, 1) = 1
  AND ka.statut_validation_kyc = 0
  -- Condition paiement (à activer plus tard) :
  -- AND EXISTS (SELECT 1 FROM paiement_contrat p
  --             JOIN contrat c ON c.num_contrat = p.num_contrat
  --             WHERE c.id_client = cl.id_client AND p.acompte_regle)
;

COMMENT ON VIEW v_clients_attente_credit IS
    'Clients dont le KYC est à valider par le crédit et qui ont au moins une évaluation du site. Plus ancien = plus prioritaire (jours_attente).';

-- Contrôle : file d'attente, du plus ancien au plus récent
SELECT client, entite, shop, agent_terrain, date_kyc, jours_attente, nb_evaluations, nb_contrats, nb_tentatives_scoring
FROM v_clients_attente_credit
ORDER BY jours_attente DESC NULLS LAST
LIMIT 20;
