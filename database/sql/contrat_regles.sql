-- =====================================================================================================
-- CRM GREENO — Règles des contrats
-- =====================================================================================================
-- À relire puis exécuter à la main (pgAdmin ou psql), en une seule fois, sur la base Supabase.
-- Script idempotent : il peut être relancé sans risque (IF NOT EXISTS, CREATE OR REPLACE, contrôles).
--
-- Règles métier :
--   • un client a un seul KYC actif (kyc.visibilite_kyc = 1) ;
--   • un client peut avoir plusieurs évaluations de site / TDH (un TDH par kit) ;
--   • KYC et évaluations sont indépendants ; c'est le contrat qui exige les deux ;
--   • un contrat = un TDH validé (eval_site.statut_eval_site = 1) + le KYC valable du même client ;
--   • un KYC est valable s'il est actif et si sa pièce n'est pas expirée (date d'expiration vide = valable) ;
--     la validation du KYC par le crédit se fait APRÈS le contrat : elle n'est pas exigée ici ;
--   • un TDH ne peut donner qu'un seul contrat.
--
-- Ordre : 0. contrôles (lecture seule) → 1. doublons de KYC → 2. unicité du KYC → 3. table contrat
--         → 4. unicité du TDH → 5. trigger → 6. libellé du besoin en eau → 7. contrôle final.
-- =====================================================================================================


-- -----------------------------------------------------------------------------------------------------
-- 0. CONTRÔLES PRÉALABLES (lecture seule) : données qui violeraient les nouvelles règles
-- -----------------------------------------------------------------------------------------------------

-- 0.a Clients ayant plusieurs KYC actifs (seront corrigés à l'étape 1 : le plus récent reste actif)
SELECT k.id_client, count(*) AS nb_kyc_actifs, string_agg(k.id_kyc, ', ' ORDER BY k.date_kyc DESC NULLS LAST, k.id_kyc DESC) AS kyc
FROM kyc k
WHERE k.visibilite_kyc = 1
GROUP BY k.id_client
HAVING count(*) > 1
ORDER BY nb_kyc_actifs DESC;

-- 0.b KYC dont le client n'existe pas (ne devrait rien renvoyer : clé étrangère existante)
SELECT k.id_kyc, k.id_client FROM kyc k LEFT JOIN client c ON c.id_client = k.id_client WHERE c.id_client IS NULL;

-- 0.c Contrôles sur les contrats existants, si la table existe déjà
DO $$
DECLARE
    v_ligne record;
BEGIN
    IF to_regclass('public.contrat') IS NULL THEN
        RAISE NOTICE 'Table contrat absente : elle sera créée à l''étape 3.';
        RETURN;
    END IF;

    -- TDH utilisés par plusieurs contrats
    FOR v_ligne IN EXECUTE 'SELECT id_eval, count(*) AS n FROM contrat WHERE id_eval IS NOT NULL GROUP BY id_eval HAVING count(*) > 1' LOOP
        RAISE NOTICE 'TDH % utilisé par % contrats', v_ligne.id_eval, v_ligne.n;
    END LOOP;

    -- Contrats sur un TDH non validé, ou dont le TDH / KYC appartient à un autre client
    FOR v_ligne IN EXECUTE '
        SELECT ct.id_contrat, ct.id_client, e.id_client AS client_tdh, e.statut_eval_site, k.id_client AS client_kyc
        FROM contrat ct
        LEFT JOIN eval_site e ON e.id_eval_site = ct.id_eval
        LEFT JOIN kyc k ON k.id_kyc = ct.id_kyc
        WHERE e.id_eval_site IS NULL OR coalesce(e.statut_eval_site, 0) <> 1
           OR e.id_client <> ct.id_client OR k.id_client IS DISTINCT FROM ct.id_client' LOOP
        RAISE NOTICE 'Contrat % incohérent (client %, client du TDH %, statut TDH %, client du KYC %)',
            v_ligne.id_contrat, v_ligne.id_client, v_ligne.client_tdh, v_ligne.statut_eval_site, v_ligne.client_kyc;
    END LOOP;
END $$;


-- -----------------------------------------------------------------------------------------------------
-- 1. DOUBLONS DE KYC : on garde le plus récent actif, les autres passent à visibilite_kyc = 0
--    (rien n'est supprimé ; les fiches restent consultables)
-- -----------------------------------------------------------------------------------------------------
WITH classes AS (
    SELECT id_kyc,
           row_number() OVER (PARTITION BY id_client ORDER BY date_kyc DESC NULLS LAST, id_kyc DESC) AS rang
    FROM kyc
    WHERE visibilite_kyc = 1
)
UPDATE kyc SET visibilite_kyc = 0
FROM classes
WHERE kyc.id_kyc = classes.id_kyc AND classes.rang > 1;


-- -----------------------------------------------------------------------------------------------------
-- 2. UN SEUL KYC ACTIF PAR CLIENT
--    Index unique partiel : les anciennes fiches désactivées (visibilite_kyc = 0) restent possibles.
-- -----------------------------------------------------------------------------------------------------
CREATE UNIQUE INDEX IF NOT EXISTS kyc_un_actif_par_client ON kyc (id_client) WHERE visibilite_kyc = 1;


-- -----------------------------------------------------------------------------------------------------
-- 3. TABLE CONTRAT (créée si absente) et colonnes id_eval / id_kyc avec leurs clés étrangères
-- -----------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contrat (
    id_contrat     varchar(20)  PRIMARY KEY,
    num_contrat    varchar(30)  NOT NULL,
    id_client      varchar(20)  NOT NULL REFERENCES client (id_client),
    id_prod        integer      REFERENCES produit (id_prod),
    id_type_vente  integer      REFERENCES type_vente (id_type_vente),
    montant        numeric(14, 2),
    date_contrat   date         NOT NULL DEFAULT current_date,
    statut         varchar(20)  NOT NULL DEFAULT 'actif',   -- actif | resilie | termine
    id_agent       integer      REFERENCES agent (id_agent),
    created_at     timestamp(0) NULL,
    updated_at     timestamp(0) NULL
);

ALTER TABLE contrat ADD COLUMN IF NOT EXISTS id_eval varchar(20);
ALTER TABLE contrat ADD COLUMN IF NOT EXISTS id_kyc  varchar(20);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'contrat_id_eval_foreign') THEN
        ALTER TABLE contrat ADD CONSTRAINT contrat_id_eval_foreign FOREIGN KEY (id_eval) REFERENCES eval_site (id_eval_site);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'contrat_id_kyc_foreign') THEN
        ALTER TABLE contrat ADD CONSTRAINT contrat_id_kyc_foreign FOREIGN KEY (id_kyc) REFERENCES kyc (id_kyc);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'contrat_statut_check') THEN
        ALTER TABLE contrat ADD CONSTRAINT contrat_statut_check CHECK (statut IN ('actif', 'resilie', 'termine'));
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS contrat_num_contrat_unique ON contrat (num_contrat);
CREATE INDEX IF NOT EXISTS contrat_id_client_index ON contrat (id_client);


-- -----------------------------------------------------------------------------------------------------
-- 4. UN TDH NE DONNE QU'UN SEUL CONTRAT
-- -----------------------------------------------------------------------------------------------------
CREATE UNIQUE INDEX IF NOT EXISTS contrat_id_eval_unique ON contrat (id_eval);


-- -----------------------------------------------------------------------------------------------------
-- 5. TRIGGER : refuse un contrat incohérent (messages en français, renvoyés tels quels par le CRM)
--    Vérifié à la création, et à la modification seulement si le client, le TDH ou le KYC change
--    (un ancien contrat reste modifiable — résiliation… — même si la pièce a expiré depuis).
-- -----------------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION contrat_verifier_regles() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_eval  eval_site%ROWTYPE;
    v_kyc   kyc%ROWTYPE;
    v_autre varchar;
BEGIN
    IF TG_OP = 'UPDATE'
       AND NEW.id_client IS NOT DISTINCT FROM OLD.id_client
       AND NEW.id_eval   IS NOT DISTINCT FROM OLD.id_eval
       AND NEW.id_kyc    IS NOT DISTINCT FROM OLD.id_kyc THEN
        RETURN NEW;
    END IF;

    -- TDH
    IF NEW.id_eval IS NULL THEN
        RAISE EXCEPTION 'Un contrat doit être rattaché à un dimensionnement (eval_site).' USING ERRCODE = 'P0001';
    END IF;

    SELECT * INTO v_eval FROM eval_site WHERE id_eval_site = NEW.id_eval;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Le dimensionnement % n''existe pas.', NEW.id_eval USING ERRCODE = 'P0001';
    END IF;
    IF v_eval.id_client <> NEW.id_client THEN
        RAISE EXCEPTION 'Le dimensionnement % appartient à un autre client.', NEW.id_eval USING ERRCODE = 'P0001';
    END IF;
    IF coalesce(v_eval.statut_eval_site, 0) <> 1 THEN
        RAISE EXCEPTION 'Le dimensionnement % n''est pas validé : validez-le avant de créer le contrat.', NEW.id_eval USING ERRCODE = 'P0001';
    END IF;

    SELECT id_contrat INTO v_autre FROM contrat WHERE id_eval = NEW.id_eval AND id_contrat <> NEW.id_contrat LIMIT 1;
    IF v_autre IS NOT NULL THEN
        RAISE EXCEPTION 'Ce dimensionnement a déjà donné un contrat (%). Un dimensionnement ne donne qu''un seul contrat.', v_autre USING ERRCODE = 'P0001';
    END IF;

    -- KYC
    IF NEW.id_kyc IS NULL THEN
        RAISE EXCEPTION 'Un contrat exige le KYC du client.' USING ERRCODE = 'P0001';
    END IF;

    SELECT * INTO v_kyc FROM kyc WHERE id_kyc = NEW.id_kyc;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Le KYC % n''existe pas.', NEW.id_kyc USING ERRCODE = 'P0001';
    END IF;
    IF v_kyc.id_client <> NEW.id_client THEN
        RAISE EXCEPTION 'Le KYC % appartient à un autre client.', NEW.id_kyc USING ERRCODE = 'P0001';
    END IF;
    IF coalesce(v_kyc.visibilite_kyc, 0) <> 1 THEN
        RAISE EXCEPTION 'Le KYC % n''est pas actif : il ne peut pas servir pour un contrat.', NEW.id_kyc USING ERRCODE = 'P0001';
    END IF;
    IF v_kyc.date_expir_doc IS NOT NULL AND v_kyc.date_expir_doc < current_date THEN
        RAISE EXCEPTION 'La pièce d''identité du KYC % a expiré le % : mettez à jour le KYC avant de créer le contrat.',
            NEW.id_kyc, to_char(v_kyc.date_expir_doc, 'DD/MM/YYYY') USING ERRCODE = 'P0001';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS contrat_verifier_regles ON contrat;
CREATE TRIGGER contrat_verifier_regles
    BEFORE INSERT OR UPDATE ON contrat
    FOR EACH ROW EXECUTE FUNCTION contrat_verifier_regles();


-- -----------------------------------------------------------------------------------------------------
-- 6. LIBELLÉ : le besoin en eau est saisi en litres par jour
-- -----------------------------------------------------------------------------------------------------
UPDATE formulaire_champ
SET libelle = 'Besoin en eau par jour (L)', updated_at = now()
WHERE formulaire = 'eval_site' AND cle = 'besoin_jour_eau_tdh' AND libelle <> 'Besoin en eau par jour (L)';


-- -----------------------------------------------------------------------------------------------------
-- 7. CONTRÔLE FINAL : doit renvoyer 0 ligne
-- -----------------------------------------------------------------------------------------------------
SELECT id_client, count(*) FROM kyc WHERE visibilite_kyc = 1 GROUP BY id_client HAVING count(*) > 1;
