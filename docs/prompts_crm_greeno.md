# CRM GREENO — Prompts Claude Code (cahier des charges UI/UX v1.0)

À lancer dans Claude Code depuis `C:\laragon\www\crm_harm`, **un prompt à la fois**, en validant chaque lot dans le navigateur avant de passer au suivant.
Le bloc « Contexte permanent » est à coller une seule fois dans `CLAUDE.md` à la racine du projet (Claude Code le relit à chaque session). Les prompts suivants y font référence.

---

## 0. Contexte permanent (à mettre dans CLAUDE.md)

```text
# CRM GREENO — règles du projet

Produit : CRM web métier GREENO / TEVIA, du prospect au SAV (prospection, ventes, crédit, recouvrement, installations, centre d'appels, SAV, réparations, stocks, cartographie, rapports). Priorité ordinateur de bureau, responsive.
Stack : Laravel 13, Filament 5, Livewire, Tailwind 4 (thème resources/css/filament/admin/theme.css), PostgreSQL Supabase (connexion dans .env). Interface et code en français.
Références UX : HubSpot CRM, Salesforce. Principe directeur : un espace de travail unifié autour d'un dossier client unique, pas une juxtaposition d'applications.

Base de données
- Base de PRODUCTION partagée avec des applications AppSheet. Ne jamais supprimer, vider ou renommer une table ou une colonne existante. Pas de migrate:fresh, pas de migrate:rollback.
- Nouvelles tables / colonnes : migrations non destructives (ajouts uniquement, IF NOT EXISTS), ou script SQL dans database/sql/ que je relis et exécute moi-même. Toujours me montrer le SQL avant.
- Avant de créer une table pour un module (SAV, stocks, installations, appels…), vérifie si une table équivalente existe déjà (information_schema) et propose-moi de la réutiliser.
- Tables existantes clés : client, kyc (statut_validation_kyc 0/1/2), eval_site (TDH, statut 0/1), contrat (1 contrat = 1 TDH validé + KYC valable), credit_scoring, agent (sup_agent_id, role_agent, nom_complet_agent), produit, entite, localite_commune, ville, site_shop. Vue v_clients_attente_credit (database/sql/vue_attente_credit.sql). Vue de paiement AMT à venir : une ligne par num_contrat_upya (date_deposit, statut_amt « Full Deposit », mt_deposit, stl_name_amt, acompte_regle).
- Colonnes de visibilité en smallint : caster en integer (jamais boolean, PostgreSQL refuse « false »).

Existant à conserver et réutiliser
- Fiche client 360° (app/Filament/Resources/Clients/Schemas/ClientInfolist.php) : en-tête, barre d'étapes à chevrons, résumé / onglets / prochaine action.
- Page Administration en cartes (Clusters/Parametres/Pages/Administration.php) et en-tête commun des écrans de paramètres.
- Sélecteur d'entité Greeno / Tevia dans la barre du haut (App\Support\EntiteActive + EntiteActiveScope).
- Gestion des agents (AgentResource), import crm:importer-agents, dimensionnement des pompes (produits compatibles selon TDH et besoin en eau).
- Sauvegarde des fichiers modifiés dans storage/app/sauvegarde-design/<lot>/ avant toute modification.

Design system (cahier des charges §3)
- Vert GREENO #159447 (principal), bleu marine #142D4E (navigation), fond #F5F7FA, cartes #FFFFFF, texte #202B3C, info #2878D0, avertissement #F2A43A, erreur/retard #D94343, terminé #218653. Police Inter.
- Boutons principaux réservés aux actions prioritaires ; bordures discrètes, ombres légères, rayons uniformes (12–16 px), interfaces aérées.
- Un statut n'est JAMAIS signalé par la couleur seule : toujours pastille + libellé (+ icône si utile).
- Composants communs à réutiliser partout (pas de HTML dupliqué) : pastille de statut, carte indicateur cliquable, en-tête de page, état vide, bandeau d'alerte, chronologie.
- États UX obligatoires sur chaque écran : chargement (squelettes), liste vide, recherche sans résultat, erreur de chargement, formulaire invalide (erreur sous le champ), enregistrement en cours, succès (notification), accès refusé, dossier bloqué (motif + action), données obsolètes / dernière synchronisation.
- Formulaires en sections, champs obligatoires signalés, confirmation avant toute action irréversible, prévention des doublons clients (téléphone, n° de pièce).
- Retour à la liste sans perdre filtres, tri et pagination (persistance dans la session / query string).

Méthode
- Un lot à la fois. Commence par lire le code existant concerné et me résumer ce que tu vas changer. Montre le SQL avant de l'exécuter.
- Après chaque lot : php -l sur les fichiers, tests Pest sur une base SQLite de test (jamais la production), npm run build, puis donne-moi les URL à vérifier sur http://crm_harm.test et la liste des fichiers modifiés.
- Si une règle métier n'est pas claire, demande-moi plutôt que d'inventer (les statuts proposés dans le cahier des charges sont à aligner sur les processus officiels GREENO).
```

---

## Lot 1 — Fondations : charte, navigation, barre du haut

```text
Lot 1 du cahier des charges CRM GREENO : fondations visuelles et navigation. Lis CLAUDE.md d'abord.

1. Charte : applique la palette du design system dans AdminPanelProvider (primary #159447, info #2878D0, warning #F2A43A, danger #D94343, success #218653) et dans theme.css (variables --gr-*). Menu latéral en bleu marine #142D4E avec texte et icônes clairs, élément actif en vert. Vérifie le contraste (≥ 4,5:1) des textes sur le bleu marine.
2. Navigation : organise le menu latéral selon le menu principal du cahier des charges, dans cet ordre : Tableau de bord, CRM et clients, Prospection, Ventes, Crédit et recouvrement, Installations, Centre d'appels, SAV, Réparations, Stocks, Cartographie, Rapports et statistiques, Administration et paramètres. Les modules pas encore construits n'apparaissent pas (pas de liens morts). Prépare la structure (groupes de navigation, ordre) pour les ajouter facilement.
3. Barre supérieure : recherche globale (clients par nom, téléphone, identifiant, n° de pièce, n° de contrat UPYA), bouton « + Créer » (menu : client, prospect, ticket SAV… selon ce qui existe), centre de notifications Filament (notifications en base), raccourci « Mes tâches », profil utilisateur. Garde le sélecteur d'entité existant.
4. Composants communs Blade réutilisables : pastille de statut (couleur + libellé + icône, à partir d'une table de correspondance statut → ton unique pour tout le CRM), carte indicateur cliquable (valeur, libellé, évolution, lien vers la liste filtrée), état vide, bandeau d'alerte (info / avertissement / critique).
5. Crée un fichier app/Support/Statuts.php qui centralise tous les statuts du CRM (libellé, couleur, icône, ordre) : ils serviront aux tableaux, fiches, cartes et marqueurs de la cartographie.

Montre-moi le résultat sur la liste des clients et la fiche client avant de continuer.
```

## Lot 2 — Profils et droits d'accès

```text
Lot 2 : profils utilisateurs et droits (cahier des charges §18). Lis CLAUDE.md.

- Relie chaque utilisateur à un agent (users.id_agent, migration non destructive). canAccessPanel refuse un utilisateur inactif ou dont l'agent a une date de sortie.
- Installe spatie/laravel-permission et Filament Shield (donne-moi les commandes composer à lancer, je les exécute). Écran « Rôles et permissions » dans Administration (carte dans la page Administration).
- Rôles initiaux : Direction générale, Responsable commercial, Commercial terrain, Responsable crédit, Agent de recouvrement, Responsable installation, Technicien, Agent centre d'appels, Responsable SAV, Technicien réparateur, Gestionnaire de stock, Administrateur.
- Permissions par module ET par action : consulter, créer, modifier, valider, clôturer, exporter, supprimer. Permissions métier : valider KYC, valider évaluation, créer contrat, envoyer au crédit, décision crédit, valider mouvement de stock.
- Périmètre des données (global scopes, en plus du filtre d'entité) : commercial terrain = ses clients ; responsable = son équipe (agents dont il est le supérieur, récursif) ; rôles régionaux = leur zone ; direction / administrateur = tout. Données financières et documents KYC visibles seulement par les rôles autorisés.
- Menus, boutons et actions masqués selon les permissions ; accès direct par URL → page « Accès refusé » claire.
- Propose une matrice rôles × permissions par défaut dans un tableau, que je valide avant de l'appliquer. Tests Pest sur les cas : commercial ne voit pas le client d'un autre, technicien ne voit pas les montants, etc.
```

## Lot 3 — Tableau de bord général

```text
Lot 3 : écran Tableau de bord (cahier des charges §4). Lis CLAUDE.md.

- Zone supérieure : titre, sélecteur de période (aujourd'hui, 7 j, mois, trimestre, personnalisée), filtres région / zone / agence (shop) / commercial, bouton d'export.
- Indicateurs (cartes cliquables vers la liste filtrée) : nouveaux prospects, ventes réalisées, montant des ventes, dossiers en attente de validation crédit (v_clients_attente_credit), installations en attente, créances en retard, tickets SAV ouverts, réparations en cours. Un indicateur dont la source n'existe pas encore affiche « Bientôt disponible » (pas de faux chiffre).
- Zone analytique : évolution des ventes, entonnoir de conversion, répartition des dossiers par statut, échéances et retards, performance des commerciaux, répartition géographique (lien vers la cartographie).
- Zone opérationnelle : alertes prioritaires, dernières activités, dossiers nécessitant une action, tâches en retard.
- Chaque chiffre indique sa période et sa date d'actualisation. Requêtes optimisées (agrégats SQL, cache court de 5 minutes), widgets chargés en différé (lazy) avec squelettes.
- Le tableau de bord respecte le filtre d'entité et le périmètre de l'utilisateur.
```

## Lot 4 — Liste des clients

```text
Lot 4 : écran Liste des clients (cahier des charges §5). Lis CLAUDE.md et reprends ClientsTable existant.

- En-tête : titre « Clients », nombre total, boutons « Nouveau client », « Importer » (selon permission), export des résultats autorisés.
- Onglets / indicateurs rapides : Tous, Nouveaux, En cours de traitement, Actifs, Nécessitant une action (avec compteurs).
- Colonnes : ID client, nom complet, téléphone, localité, commercial, source, type de pompe, statut commercial, statut crédit, statut installation, dernière activité, prochaine action. Colonnes masquables (choix mémorisé par utilisateur).
- Filtres combinables : recherche nom / téléphone / identifiant, région, zone, localité, commercial, source (terrain, Facebook, autres), type de pompe, statuts commercial / crédit / installation, retard, période de création.
- Clic sur une ligne → fiche client. Tri, filtres et pagination conservés au retour de la fiche. Actions groupées seulement si autorisées.
- Statuts calculés (commercial, crédit, installation, prochaine action) : propose-moi la règle de calcul de chacun à partir des tables existantes avant de l'implémenter ; calcule-les en SQL (sous-requêtes / vue) pour garder la liste rapide sur 10 000+ clients.
- États : liste vide, aucun résultat (avec bouton « Effacer les filtres »), erreur.
```

## Lot 5 — Fiche client complète

```text
Lot 5 : fiche client (cahier des charges §6). Lis CLAUDE.md. Pars de la fiche 360° existante (ClientInfolist) : on l'enrichit, on ne la refait pas.

- En-tête : nom, identifiant, téléphone, localisation, commercial, statut global, date de création ; actions rapides : appeler, ajouter une note, créer une tâche, enregistrer une activité (notes, tâches et activités dans de nouvelles tables liées au client, avec auteur et date).
- Onglets : Vue d'ensemble (progression, prochaine action, alertes, résumé ventes / crédit / installation / SAV) · Informations client (identité, site, documents autorisés, historique des modifications) · Prospection et ventes (source, activités, visites, évaluations TDH, devis, contrats) · Crédit et paiements (dossier crédit, apport, statut AMT du dépôt via num_contrat_upya, échéancier, paiements, solde, retards et recouvrement) · Installation · Centre d'appels · SAV et réparations · Historique global (chronologie de tous les événements, auteur, date, commentaires, pièces jointes).
- Les onglets dont le module n'existe pas encore affichent un état vide « Module à venir », pas d'erreur.
- Produits / évaluations et contrats : intègre les gestionnaires de relations existants dans l'onglet « Prospection et ventes » plutôt que sous la fiche.
- Historique des modifications : journal d'audit (qui, quoi, avant / après) sur client, kyc, eval_site, contrat — propose une table d'audit ou un paquet (spatie/laravel-activitylog).
- Onglet Crédit visible seulement avec la permission correspondante.
```

## Lot 6 — Prospection commerciale

```text
Lot 6 : Prospection (cahier des charges §7). Lis CLAUDE.md.

- Les prospects sont des clients au statut prospect (pas de table séparée, pour éviter les doublons à la conversion) — vérifie avec moi ce choix par rapport aux données existantes (client, activite_propestion, prospects digitaux Facebook / WhatsApp / site).
- Étapes : Nouveau → Contacté → Visite à planifier → Visite effectuée → Évaluation technique → Devis transmis → Négociation → Converti / Perdu (motif de perte obligatoire). Étapes et motifs configurables dans Administration.
- Vues : tableau de suivi, Kanban par étape (glisser-déposer avec contrôle des règles : on ne passe pas à « Évaluation technique » sans évaluation TDH), historique des activités.
- Affichage : nom, téléphone, source, commercial, dernier contact, prochaine action, priorité.
- Actions : créer, affecter / réaffecter, programmer un appel ou une visite, compte rendu, besoins du client, convertir en client sans doublon (détection par téléphone et n° de pièce, fusion proposée).
```

## Lot 7 — Ventes

```text
Lot 7 : Ventes (cahier des charges §8). Lis CLAUDE.md. Le contrat existant (1 contrat = 1 TDH validé + KYC valable, produit compatible avec le TDH) reste la base.

- Liste des ventes avec filtres période, commercial, région, statut ; fiche vente (produit, montant, historique des validations, documents).
- Statuts : Brouillon → Devis transmis → En attente de décision → Acceptée → En attente de dépôt → Confirmée → Transmise au crédit et aux opérations. Le passage à « Confirmée » exige le dépôt AMT (statut_amt = Full Deposit sur le num_contrat_upya) ; impossible de contourner (contrôle serveur + trigger si nécessaire).
- Chaque changement de statut est tracé (auteur, date, motif).
```

## Lot 8 — Crédit et recouvrement

```text
Lot 8 : Crédit et recouvrement (cahier des charges §9). Lis CLAUDE.md.

- Synchronisation AMT : connexion lecture seule « paiements » (config/database.php + .env), commande planifiée toutes les 15 minutes qui copie la vue AMT (une ligne par num_contrat_upya) dans une table locale paiement_contrat, avec date de dernière synchronisation affichée partout où ces données apparaissent. Commande crm:tester-paiements pour vérifier l'accès.
- File crédit : page basée sur v_clients_attente_credit + condition paiement (Full Deposit), colonnes de l'onglet « Vue attente crédit » de la spécification (num_piece_id, date du deposit, statut_amt, montant, STL, superviseur, date TDH, client, parent proche, localité, num_contrat_upya, dernier appel et statut, usage, type de pompe, source d'eau, téléphone). Tri par ancienneté du dépôt.
- Vue principale : dossiers à analyser, en attente de pièces, approuvés, refusés, échéances à venir, retards, montants échus et soldes.
- Fiche crédit : solvabilité (credit scoring existant), financement, apport, échéancier, paiements, reste dû, retard, historique des décisions et motifs (traçabilité obligatoire).
- Recouvrement : clients à relancer, jours de retard, montant dû, dernière tentative, résultat, prochaine date, agent. Lien avec les SMS de relance existants si possible.
- Accès strict : rôles crédit / recouvrement / direction uniquement.
```

## Lot 9 — Installations

```text
Lot 9 : Installations (cahier des charges §10). Lis CLAUDE.md. Vérifie d'abord s'il existe une table d'installation dans la base.

- Vues : liste, calendrier, Kanban par statut, carte des interventions.
- Dossier : client, coordonnées, GPS, équipement, préparation, technicien, date prévue / réelle, pièces requises (réservation dans le stock), compte rendu et photos justificatives.
- Statuts : En attente de préparation → Prêt à planifier → Planifiée → En cours → Installée → À contrôler → Clôturée. Dossier bloqué : motif et action affichés.
- Création automatique du dossier quand la vente est « Transmise aux opérations » et le crédit approuvé (à confirmer avec moi).
```

## Lot 10 — Centre d'appels

```text
Lot 10 : Centre d'appels (cahier des charges §11). Lis CLAUDE.md.

- File des appels et demandes, fiche client accessible en un clic (recherche par numéro appelant), historique des interactions, motif, résultat, notes, rappel programmé, transfert vers un service.
- Statuts : Nouveau → Affecté → En cours → En attente → Résolu / Escaladé. Délai de traitement configurable ; les demandes en dépassement remontent en alerte.
- Les appels du credit scoring existant apparaissent dans l'historique des interactions du client.
```

## Lot 11 — SAV et réparations

```text
Lot 11 : SAV et Réparations (cahier des charges §12 et §13). Lis CLAUDE.md. L'application SAV AppSheet existante utilise peut-être déjà des tables de tickets : vérifie et propose de les réutiliser.

- SAV : n° de ticket, client, équipement, ouverture, motif, priorité, responsable, délai, dernière mise à jour. Workflow : Nouveau → Qualifié → Affecté → En traitement → En attente (client / pièce) → Résolu → Clôturé.
- Un ticket nécessitant une intervention génère un dossier de réparation lié au même client et équipement.
- Réparations : référence, ticket d'origine, diagnostic, technicien, pièces (disponibilité depuis le stock, consommation tracée), temps passé, coût, tests, remise en service. Workflow : À diagnostiquer → Diagnostic effectué → En attente de pièces → En réparation → En test → Réparé → Restitué / Clôturé. La clôture exige le résultat.
```

## Lot 12 — Stocks

```text
Lot 12 : Gestion des stocks (cahier des charges §14). Lis CLAUDE.md.

- Indicateurs : références disponibles, stock faible, ruptures, entrées / sorties, réservés aux installations, affectés aux réparations, mouvements en attente de validation.
- Tableau : référence, désignation, catégorie, dépôt, disponible, réservé, minimum, statut, dernier mouvement.
- Actions : réception, transfert entre dépôts, affectation à une installation, consommation pour une réparation, inventaire, historique.
- Le stock n'est jamais modifié directement : uniquement par des mouvements tracés (auteur, date, motif), validés selon les permissions. Quantités calculées à partir des mouvements.
```

## Lot 13 — Cartographie

```text
Lot 13 : Cartographie opérationnelle (cahier des charges §15). Lis CLAUDE.md.

- Carte interactive (Leaflet + OpenStreetMap) des clients (GPS des localités / sites), installations en attente, interventions SAV et réparations.
- Filtres région, zone, localité, commercial, statut ; marqueurs regroupés (clusters) ; couleurs et libellés issus de app/Support/Statuts.php.
- Clic sur un marqueur → résumé et lien vers la fiche ; bascule carte ↔ liste filtrée avec les mêmes filtres.
```

## Lot 14 — Rapports et statistiques

```text
Lot 14 : Rapports (cahier des charges §16). Lis CLAUDE.md.

- Rapports : performance commerciale, conversion des prospects, activité des commerciaux, portefeuille de crédit, recouvrement et impayés, délais d'installation, productivité du centre d'appels, SAV et délais de résolution, réparations et consommation de pièces, état des stocks.
- Chaque rapport : filtres, période de référence, date de dernière actualisation, export Excel / CSV selon permission.
- Les définitions des indicateurs sont écrites dans un fichier docs/indicateurs.md (formule, source, période) et utilisées partout (tableau de bord, rapports) pour que les chiffres soient identiques.
```

## Lot 15 — Notifications et alertes

```text
Lot 15 : Notifications et alertes (cahier des charges §17). Lis CLAUDE.md.

- Trois niveaux : information, avertissement, critique.
- Règles : dossier incomplet, validation crédit en attente, installation hors délai, échéance proche / dépassée, ticket SAV non traité, pièce indisponible, stock sous le minimum, tâche non exécutée.
- Chaque alerte : dossier concerné, responsable, date limite, action attendue, lien direct.
- Délais configurables dans Administration (une carte « Règles d'alerte »). Calcul par une commande planifiée ; notifications Filament en base, et e-mail / SMS pour les critiques (à confirmer).
```

---

### Ordre conseillé

Lots 1 et 2 d'abord (tout le reste en dépend), puis 4 et 5 (clients), 8 (crédit, déjà bien avancé), 3 (tableau de bord, qui réutilise les autres), puis les modules opérationnels selon tes priorités.
