<?php

namespace App\Formulaires;

/**
 * Questions des formulaires à l'installation. Tout est ensuite modifiable dans Paramètres.
 */
class ChampsParDefaut
{
    public static function client(): array
    {
        return self::normaliser('client', [
            // Identité
            ['cle' => 'nom_client', 'libelle' => 'Nom', 'type' => 'texte', 'section' => 'Identité', 'obligatoire' => true, 'verrouille' => true],
            ['cle' => 'prenoms_client', 'libelle' => 'Prénoms', 'type' => 'texte', 'section' => 'Identité'],
            ['cle' => 'genre_client', 'libelle' => 'Genre', 'type' => 'boutons', 'section' => 'Identité', 'source_liste' => 'genre'],
            ['cle' => 'profession_client', 'libelle' => 'Profession', 'type' => 'texte', 'section' => 'Identité'],
            ['cle' => 'id_type_piece_id', 'libelle' => 'Pièce d\'identité', 'type' => 'liste', 'section' => 'Identité', 'source_liste' => 'type_piece_id'],

            // Contact
            ['cle' => 'contact1_client', 'libelle' => 'Téléphone principal', 'type' => 'telephone', 'section' => 'Contact', 'obligatoire' => true],
            ['cle' => 'contact2_client', 'libelle' => 'Téléphone secondaire', 'type' => 'telephone', 'section' => 'Contact'],
            ['cle' => 'email_client', 'libelle' => 'E-mail', 'type' => 'email', 'section' => 'Contact', 'pleine_largeur' => true],

            // Localisation : la ville ne s'enregistre pas, elle filtre la liste des localités
            // Ville obligatoire ; la localité précise est facultative (sinon : la localité du même nom que la ville)
            ['cle' => 'ville_filtre', 'libelle' => 'Ville', 'type' => 'liste', 'section' => 'Localisation', 'source_liste' => 'ville', 'stockage' => 'aucun',
                'obligatoire' => true, 'aide' => 'Ville du client.'],
            ['cle' => 'id_localite_commune', 'libelle' => 'Localité (quartier, village)', 'type' => 'liste', 'section' => 'Localisation', 'source_liste' => 'localite_commune',
                'liste_parent' => 'ville_filtre', 'liste_parent_colonne' => 'id_ville',
                'aide' => 'Facultatif : laissé vide, le client est rattaché à la ville choisie.'],
            ['cle' => 'indication_site_client', 'libelle' => 'Indication du site', 'type' => 'zone_texte', 'section' => 'Localisation', 'pleine_largeur' => true,
                'aide' => 'Repère pour trouver le client : quartier, chantier, à côté de…'],

            // Suivi : la source filtre la liste des canaux de vente
            ['cle' => 'date_client', 'libelle' => 'Date d\'enregistrement', 'type' => 'date', 'section' => 'Suivi', 'obligatoire' => true, 'defaut' => 'aujourdhui'],
            ['cle' => 'id_src_client', 'libelle' => 'Source', 'type' => 'boutons', 'section' => 'Suivi', 'source_liste' => 'src_client',
                'aide' => 'Si vide, la source est déduite du canal de vente.'],
            ['cle' => 'id_canal_vente', 'libelle' => 'Canal de vente', 'type' => 'liste', 'section' => 'Suivi', 'source_liste' => 'canal_vente',
                'obligatoire' => true, 'liste_parent' => 'id_src_client', 'liste_parent_colonne' => 'id_src_client'],
            ['cle' => 'entite_id_client', 'libelle' => 'Entité', 'type' => 'liste', 'section' => 'Suivi', 'source_liste' => 'entite', 'defaut' => '1'],

            // Projet : l'horizon d'achat n'est demandé que si un système est choisi
            ['cle' => 'systeme_souhaite_client', 'libelle' => 'Système souhaité', 'type' => 'liste', 'section' => 'Projet',
                'source_liste' => ListesReference::PERSONNALISEE, 'options' => self::options(['TEVIA ACCES', 'TEVIA ESSENTIEL'])],
            ['cle' => 'horizon_achat_client', 'libelle' => 'Horizon d\'achat', 'type' => 'liste', 'section' => 'Projet',
                'source_liste' => ListesReference::PERSONNALISEE, 'options' => self::options(['D\'ici 1 semaine', 'D\'ici 2 semaines', 'D\'ici 1 mois', 'Plus tard']),
                'conditions' => [['champ' => 'systeme_souhaite_client', 'operateur' => 'rempli', 'valeurs' => []]]],
            ['cle' => 'connu_via_client', 'libelle' => 'Connu via', 'type' => 'liste', 'section' => 'Projet',
                'source_liste' => ListesReference::PERSONNALISEE, 'options' => self::options(['Action terrain', 'Une connaissance', 'Réseaux sociaux', 'Radio', 'Affichage', 'Autre'])],

            // Informations techniques (masquées par défaut)
            ['cle' => 'visibilite_client', 'libelle' => 'Client actif', 'type' => 'oui_non', 'section' => 'Informations techniques', 'defaut' => '1'],
            ['cle' => 'id_act_pros', 'libelle' => 'Action de prospection', 'type' => 'texte', 'section' => 'Informations techniques', 'visible' => false],
            ['cle' => 'id_supp_cnl', 'libelle' => 'id_supp_cnl', 'type' => 'nombre', 'section' => 'Informations techniques', 'visible' => false],
            ['cle' => 'id_client_amt_pros', 'libelle' => 'id_client_amt_pros', 'type' => 'texte', 'section' => 'Informations techniques', 'visible' => false],
            ['cle' => 'id_client_surge', 'libelle' => 'id_client_surge', 'type' => 'texte', 'section' => 'Informations techniques', 'visible' => false],
            ['cle' => 'id_client_zeci', 'libelle' => 'id_client_zeci', 'type' => 'texte', 'section' => 'Informations techniques', 'visible' => false],
        ]);
    }

    public static function kyc(): array
    {
        return self::normaliser('kyc', [
            // Pièce d'identité
            ['cle' => 'type_piece_id', 'libelle' => 'Type de pièce', 'type' => 'liste', 'section' => 'Pièce d\'identité', 'source_liste' => 'type_piece_id', 'obligatoire' => true],
            ['cle' => 'num_piece_id', 'libelle' => 'Numéro de la pièce', 'type' => 'texte', 'section' => 'Pièce d\'identité', 'obligatoire' => true],
            ['cle' => 'date_edit_doc', 'libelle' => 'Date de délivrance', 'type' => 'date', 'section' => 'Pièce d\'identité'],
            ['cle' => 'date_expir_doc', 'libelle' => 'Date d\'expiration', 'type' => 'date', 'section' => 'Pièce d\'identité'],
            ['cle' => 'd_nais', 'libelle' => 'Date de naissance', 'type' => 'date', 'section' => 'Pièce d\'identité', 'obligatoire' => true],

            // Photos (appareil photo du téléphone ou fichier)
            ['cle' => 'photo_id_face1', 'libelle' => 'Recto de la pièce', 'type' => 'photo', 'section' => 'Photos'],
            ['cle' => 'photo_id_face2', 'libelle' => 'Verso de la pièce', 'type' => 'photo', 'section' => 'Photos'],
            ['cle' => 'photo_id_av_client', 'libelle' => 'Client avec sa pièce en main', 'type' => 'photo', 'section' => 'Photos'],

            // Personne à contacter
            ['cle' => 'nom_parent_proche', 'libelle' => 'Nom du proche', 'type' => 'texte', 'section' => 'Personne à contacter', 'obligatoire' => true],
            ['cle' => 'id_lien_par', 'libelle' => 'Lien de parenté', 'type' => 'liste', 'section' => 'Personne à contacter', 'source_liste' => 'lien_par', 'obligatoire' => true],
            // Texte libre du système d'origine (« FRERE », « Épouse »…), conservé mais masqué
            ['cle' => 'lien_parente', 'libelle' => 'Lien de parenté (texte d\'origine)', 'type' => 'texte', 'section' => 'Personne à contacter', 'visible' => false],
            ['cle' => 'contact_parent_1', 'libelle' => 'Téléphone du proche', 'type' => 'telephone', 'section' => 'Personne à contacter', 'obligatoire' => true],
            ['cle' => 'contact_parent_2', 'libelle' => 'Autre téléphone du proche', 'type' => 'telephone', 'section' => 'Personne à contacter'],

            // Vente
            ['cle' => 'date_kyc', 'libelle' => 'Date du KYC', 'type' => 'date', 'section' => 'Vente', 'obligatoire' => true, 'defaut' => 'aujourdhui'],
            ['cle' => 'id_type_vente', 'libelle' => 'Type de vente', 'type' => 'boutons', 'section' => 'Vente', 'source_liste' => 'type_vente'],
            ['cle' => 'id_agent', 'libelle' => 'Agent', 'type' => 'liste', 'section' => 'Vente', 'source_liste' => 'agent', 'aide' => 'Agent qui a réalisé le KYC.'],
            ['cle' => 'ref_client_upya', 'libelle' => 'Référence UPYA', 'type' => 'texte', 'section' => 'Vente'],
            ['cle' => 'email', 'libelle' => 'E-mail', 'type' => 'email', 'section' => 'Vente', 'visible' => false],
            ['cle' => 'entite', 'libelle' => 'Entité', 'type' => 'liste', 'section' => 'Vente', 'source_liste' => 'entite', 'defaut' => '1', 'visible' => false],
        ]);
    }

    /**
     * Évaluation du site. Les colonnes general / tdh / shs / sst du système d'origine sont les 4 sections.
     * Les questions texte/nombre/liste ont un type modifiable (colonnes texte en base).
     */
    public static function evalSite(): array
    {
        $ouiNon = fn (string $cle, string $libelle, string $section, array $extra = []) => ['cle' => $cle, 'libelle' => $libelle, 'type' => 'oui_non', 'section' => $section] + $extra;
        $si = fn (string $cle, string $operateur = 'egal', array $valeurs = ['1']) => ['conditions' => [['champ' => $cle, 'operateur' => $operateur, 'valeurs' => $valeurs]]];
        $g = 'Général';
        $tdh = 'Pompage solaire (TDH)';
        $shs = 'Kit solaire maison (SHS)';
        $sst = 'Sécurité (SST)';

        $champs = self::normaliser('eval_site', [
            // Général
            ['cle' => 'date_eval_site', 'libelle' => 'Date de l\'évaluation', 'type' => 'date', 'section' => $g, 'obligatoire' => true, 'defaut' => 'aujourdhui'],
            ['cle' => 'id_agent', 'libelle' => 'Agent', 'type' => 'liste', 'section' => $g, 'source_liste' => 'agent', 'aide' => 'Agent qui a réalisé l\'évaluation.'],
            ['cle' => 'id_type_eval_site', 'libelle' => 'Type d\'évaluation', 'type' => 'boutons', 'section' => $g, 'source_liste' => 'type_eval_site', 'obligatoire' => true],
            ['cle' => 'id_usg_kit', 'libelle' => 'Usage du kit', 'type' => 'boutons', 'section' => $g, 'source_liste' => 'usage_kit'],
            ['cle' => 'id_prod', 'libelle' => 'Produit proposé', 'type' => 'liste', 'section' => $g, 'source_liste' => 'produit'],
            ['cle' => 'latitude_eval_site', 'libelle' => 'Latitude', 'type' => 'gps_latitude', 'section' => $g, 'aide' => 'Bouton ⌖ : position actuelle du téléphone.'],
            ['cle' => 'longitude_eval_site', 'libelle' => 'Longitude', 'type' => 'gps_longitude', 'section' => $g],
            ['cle' => 'etat_route_eval_site', 'libelle' => 'État de la route', 'type' => 'liste', 'section' => $g, 'source_liste' => ListesReference::PERSONNALISEE,
                'options' => self::options(['Bon', 'Moyen', 'Mauvais', 'Impraticable'])],
            ['cle' => 'accessiblite_eval_site', 'libelle' => 'Accessibilité du site', 'type' => 'liste', 'section' => $g, 'source_liste' => ListesReference::PERSONNALISEE,
                'options' => self::options(['Facile', 'Moyenne', 'Difficile'])],
            ['cle' => 'best_couv_reseau_localite', 'libelle' => 'Meilleure couverture réseau', 'type' => 'boutons', 'section' => $g, 'source_liste' => ListesReference::PERSONNALISEE,
                'options' => self::options(['Orange', 'MTN', 'Moov', 'Aucune'])],
            ['cle' => 'src_energie_id_eval_site_cs', 'libelle' => 'Source d\'énergie actuelle', 'type' => 'boutons', 'section' => $g, 'source_liste' => 'src_energie'],
            ['cle' => 'etat_toiture_eval_site', 'libelle' => 'État de la toiture', 'type' => 'liste', 'section' => $g, 'source_liste' => ListesReference::PERSONNALISEE,
                'options' => self::options(['Bon', 'Moyen', 'Mauvais'])],
            ['cle' => 'surf_disp_inst_eval_site', 'libelle' => 'Surface disponible pour l\'installation (m²)', 'type' => 'nombre', 'section' => $g],
            $ouiNon('pres_espa_ext_disp_eval_site', 'Espace extérieur disponible', $g),
            $ouiNon('pres_ombre_site_eval_site', 'Présence d\'ombre sur le site', $g),
            ['cle' => 'src_ombre_eval_site', 'libelle' => 'Source de l\'ombre', 'type' => 'liste', 'section' => $g, 'source_liste' => ListesReference::PERSONNALISEE,
                'options' => self::options(['Arbre', 'Bâtiment', 'Relief', 'Autre'])] + $si('pres_ombre_site_eval_site'),
            ['cle' => 'autre_src_ombr_eval_site', 'libelle' => 'Autre source d\'ombre', 'type' => 'texte', 'section' => $g] + $si('src_ombre_eval_site', 'egal', ['Autre']),
            ['cle' => 'statut_eval_site', 'libelle' => 'Statut de l\'évaluation', 'type' => 'boutons', 'section' => $g, 'source_liste' => ListesReference::PERSONNALISEE,
                'options' => [['valeur' => '0', 'libelle' => 'À valider', 'parent' => null], ['valeur' => '1', 'libelle' => 'Validée', 'parent' => null]], 'defaut' => '0'],

            // Pompage solaire (TDH)
            ['cle' => 'besoin_jour_eau_tdh', 'libelle' => 'Besoin en eau par jour (m³)', 'type' => 'nombre', 'section' => $tdh],
            ['cle' => 'alt_src_tdh', 'libelle' => 'Altitude de la source d\'eau (m)', 'type' => 'nombre', 'section' => $tdh],
            ['cle' => 'alt_site_exp_tdh', 'libelle' => 'Altitude du site d\'exploitation (m)', 'type' => 'nombre', 'section' => $tdh],
            ['cle' => 'diametre_puit_forage_tdh', 'libelle' => 'Diamètre du puits / forage (cm)', 'type' => 'nombre', 'section' => $tdh],
            ['cle' => 'profondeur_src_eau_tdh', 'libelle' => 'Profondeur de la source d\'eau (m)', 'type' => 'nombre', 'section' => $tdh],
            ['cle' => 'niveau_statique_eau_tdh', 'libelle' => 'Niveau statique de l\'eau (m)', 'type' => 'nombre', 'section' => $tdh],
            ['cle' => 'prof_reglage_pomp_tdh', 'libelle' => 'Profondeur de réglage de la pompe (m)', 'type' => 'nombre', 'section' => $tdh],
            ['cle' => 'dist_src_eau_et_reservoir_tdh', 'libelle' => 'Distance source – réservoir (m)', 'type' => 'nombre', 'section' => $tdh],
            ['cle' => 'haut_elevation_verticale_tdh', 'libelle' => 'Hauteur d\'élévation verticale (m)', 'type' => 'nombre', 'section' => $tdh],
            ['cle' => 'nb_coude_connecteur', 'libelle' => 'Nombre de coudes / connecteurs', 'type' => 'nombre', 'section' => $tdh],
            $ouiNon('forage_utilise_ou_pas_tdh', 'Forage déjà utilisé', $tdh),
            $ouiNon('pompe_demonte_oupas_tdh', 'Pompe existante démontée', $tdh),
            $ouiNon('assechement_puit_tdh', 'Le puits s\'assèche-t-il ?', $tdh),
            ['cle' => 'date_deb_assechement_tdh', 'libelle' => 'Début de l\'assèchement', 'type' => 'date', 'section' => $tdh] + $si('assechement_puit_tdh'),
            ['cle' => 'date_fin_assechement_tdh', 'libelle' => 'Fin de l\'assèchement', 'type' => 'date', 'section' => $tdh] + $si('assechement_puit_tdh'),
            $ouiNon('reservoir_ou_pas_tdh', 'Réservoir existant', $tdh),
            ['cle' => 'capacite_reservoir_tdh', 'libelle' => 'Capacité du réservoir (L)', 'type' => 'nombre', 'section' => $tdh] + $si('reservoir_ou_pas_tdh'),
            ['cle' => 'haut_reservoir_tdh', 'libelle' => 'Hauteur du réservoir (m)', 'type' => 'nombre', 'section' => $tdh] + $si('reservoir_ou_pas_tdh'),
            ['cle' => 'haut_supp_reservoir_tdh', 'libelle' => 'Hauteur du support du réservoir (m)', 'type' => 'nombre', 'section' => $tdh] + $si('reservoir_ou_pas_tdh'),
            $ouiNon('client_supp_reservoir_tdh', 'Le client fournit le support', $tdh, $si('reservoir_ou_pas_tdh')),
            ['cle' => 'photo_du_reservoir_tdh', 'libelle' => 'Photo du réservoir', 'type' => 'photo', 'section' => $tdh] + $si('reservoir_ou_pas_tdh'),
            ['cle' => 'photo_supp_reservoir_tdh', 'libelle' => 'Photo du support du réservoir', 'type' => 'photo', 'section' => $tdh] + $si('reservoir_ou_pas_tdh'),
            ['cle' => 'info_const_supp_tdh', 'libelle' => 'Informations complémentaires', 'type' => 'zone_texte', 'section' => $tdh, 'pleine_largeur' => true],
            ['cle' => 'reommandation_tdh', 'libelle' => 'Recommandation', 'type' => 'zone_texte', 'section' => $tdh, 'pleine_largeur' => true],

            // Kit solaire maison (SHS)
            ['cle' => 'type_bati_shs', 'libelle' => 'Type de bâti', 'type' => 'liste', 'section' => $shs, 'source_liste' => ListesReference::PERSONNALISEE,
                'options' => self::options(['Maison en dur', 'Semi-dur', 'Banco', 'Tôle', 'Autre'])],
            ['cle' => 'nbre_de_piece_shs', 'libelle' => 'Nombre de pièces', 'type' => 'nombre', 'section' => $shs],
            ['cle' => 'dist_approx_entre_equip_shs', 'libelle' => 'Distance approximative entre équipements (m)', 'type' => 'nombre', 'section' => $shs],
            ['cle' => 'contraintes_part_site_shs', 'libelle' => 'Contraintes particulières du site', 'type' => 'zone_texte', 'section' => $shs, 'pleine_largeur' => true],
            ['cle' => 'evenutelles_contraintes_shs', 'libelle' => 'Éventuelles contraintes', 'type' => 'zone_texte', 'section' => $shs, 'pleine_largeur' => true],
            ['cle' => 'photo_maison_shs', 'libelle' => 'Photo de la maison', 'type' => 'photo', 'section' => $shs],
            ['cle' => 'photo_toiture_shs', 'libelle' => 'Photo de la toiture', 'type' => 'photo', 'section' => $shs],
            ['cle' => 'photo_esp_prev_inst_shs', 'libelle' => 'Photo de l\'emplacement prévu', 'type' => 'photo', 'section' => $shs],
            ['cle' => 'photo_inst_si_exist_shs', 'libelle' => 'Photo de l\'installation existante', 'type' => 'photo', 'section' => $shs],

            // Sécurité (SST)
            $ouiNon('etat_route_sai_pluie_sst', 'Route praticable en saison des pluies', $sst),
            $ouiNon('risk_elisem_affaissment_sst', 'Risque d\'éboulement / affaissement', $sst),
            $ouiNon('chemin_pieton_est_securise_sst', 'Chemin piéton sécurisé', $sst),
            $ouiNon('pano_solai_transprtable_sst', 'Panneaux transportables jusqu\'au site', $sst),
            $ouiNon('pres_animaux_danger_sst', 'Présence d\'animaux dangereux', $sst),
            $ouiNon('envir_stable_inondable_sst', 'Zone inondable', $sst),
            $ouiNon('risk_vol_sst', 'Risque de vol', $sst),
            $ouiNon('risk_accident_identifie_sst', 'Risque d\'accident identifié', $sst),
            $ouiNon('bon_eta_toiture_sst', 'Toiture en bon état', $sst),
            $ouiNon('struc_solide_sst', 'Structure solide', $sst),
            $ouiNon('absence_rouille_sst', 'Absence de rouille', $sst),
            $ouiNon('surface_suff_panneau_sst', 'Surface suffisante pour les panneaux', $sst),
            $ouiNon('inclin_adapte_sst', 'Inclinaison adaptée', $sst),
            $ouiNon('acces_securise_toit_sst', 'Accès sécurisé au toit', $sst),
            $ouiNon('echelle_disp_sst', 'Échelle disponible', $sst),
            $ouiNon('si_oui_bon_etat_echelle_sst', 'Échelle en bon état', $sst, $si('echelle_disp_sst')),
            $ouiNon('si_oui_echell_haut_suff_sst', 'Échelle assez haute', $sst, $si('echelle_disp_sst')),
            $ouiNon('echelle_fixable_sst', 'Échelle fixable', $sst, $si('echelle_disp_sst')),
            ['cle' => 'autre_equip_acces_sst', 'libelle' => 'Autre équipement d\'accès', 'type' => 'texte', 'section' => $sst],
            $ouiNon('risk_chute_hauteur_sst', 'Risque de chute de hauteur', $sst),
            $ouiNon('risk_glissade_pt_eau_sst', 'Risque de glissade (point d\'eau)', $sst),
            $ouiNon('besoin_fixation_special_sst', 'Besoin de fixations spéciales', $sst),
            $ouiNon('besoin_pied_support_sst', 'Besoin d\'un pied / support', $sst),
            $ouiNon('besoin_equip_securite_sst', 'Besoin d\'équipement de sécurité', $sst),
            $ouiNon('besoin_gilet_sauvet_sst', 'Besoin de gilet de sauvetage', $sst),
            ['cle' => 'commentaire_sst', 'libelle' => 'Commentaire sécurité', 'type' => 'zone_texte', 'section' => $sst, 'pleine_largeur' => true],
        ]);

        // Colonnes texte en base : on peut changer le type de réponse (sauf oui/non, dates, photos, GPS et tables de référence)
        return array_map(fn (array $champ) => $champ + [
            'type_modifiable' => in_array($champ['type'], ['texte', 'zone_texte', 'nombre'], true)
                || ($champ['source_liste'] ?? null) === ListesReference::PERSONNALISEE && $champ['cle'] !== 'statut_eval_site',
        ], $champs);
    }

    /** Sections de l'évaluation : TDH et SHS n'apparaissent que pour le type d'évaluation correspondant. */
    public static function sectionsEvalSite(): array
    {
        return [
            ['nom' => 'Général', 'ordre' => 10, 'description' => null, 'conditions' => null],
            ['nom' => 'Pompage solaire (TDH)', 'ordre' => 20, 'description' => 'Installation de pompage d\'eau.',
                'conditions' => [['champ' => 'id_type_eval_site', 'operateur' => 'egal', 'valeurs' => ['1']]]],
            ['nom' => 'Kit solaire maison (SHS)', 'ordre' => 30, 'description' => 'Kit solaire domestique.',
                'conditions' => [['champ' => 'id_type_eval_site', 'operateur' => 'egal', 'valeurs' => ['2']]]],
            ['nom' => 'Sécurité (SST)', 'ordre' => 40, 'description' => 'Santé et sécurité au travail sur le site.', 'conditions' => null],
        ];
    }

    /**
     * Credit scoring (« CDS 2 ») : questions et libellés du formulaire AppSheet d'origine
     * (colonnes q1_cs … q47_cs, en texte en base). Les codes des listes confirmés par l'export AppSheet
     * portent leur libellé ; les autres restent « Code n », à renommer dans Paramètres > Formulaire credit scoring.
     */
    public static function creditScoring(): array
    {
        $appel = 'Appel';
        $activite = 'Activité et terrain';
        $kit = 'Kit et paiement';
        $elevage = 'Élevage et agriculture';
        $eau = 'Eau et énergie';
        $revenus = 'Revenus et prêts';
        $stabilite = 'Stabilité';
        $decision = 'Décision du crédit';

        $ouiNon = fn (string $cle, string $libelle, string $section, array $extra = []) => [
            'cle' => $cle, 'libelle' => $libelle, 'type' => 'boutons', 'section' => $section,
            'source_liste' => ListesReference::PERSONNALISEE, 'options' => self::choix(['1' => 'Oui', '0' => 'Non']),
        ] + $extra;
        // Liste à codes : libellés connus, « Code n » pour les autres codes de l'historique
        $liste = fn (string $cle, string $libelle, string $section, array $connus, array $codes, string $type = 'liste', array $extra = []) => [
            'cle' => $cle, 'libelle' => $libelle, 'type' => $type, 'section' => $section,
            'source_liste' => ListesReference::PERSONNALISEE,
            'options' => self::choix(collect($codes)->mapWithKeys(fn ($code) => [(string) $code => $connus[$code] ?? 'Code '.$code])->all()),
        ] + $extra;
        $nombre = fn (string $cle, string $libelle, string $section, array $extra = []) => ['cle' => $cle, 'libelle' => $libelle, 'type' => 'nombre', 'section' => $section] + $extra;
        $si = fn (string $cle, string $valeur) => ['conditions' => [['champ' => $cle, 'operateur' => 'egal', 'valeurs' => [$valeur]]]];
        $sourcesEau = [1 => 'Forage', 3 => 'Puits'];

        $champs = self::normaliser('credit_scoring', [
            // Appel (le n° de contrat UPYA, saisi dans le contrat du client, en premier)
            ['cle' => 'num_contrat_upya', 'libelle' => 'Numéro de contrat UPYA', 'type' => 'texte', 'section' => $appel, 'defaut' => 'client.num_contrat_upya_actuel',
                'aide' => 'Repris du contrat du client. À corriger dans le contrat si besoin.'],
            ['cle' => 'date_cs', 'libelle' => 'Date du CDS 2', 'type' => 'date', 'section' => $appel, 'obligatoire' => true, 'defaut' => 'aujourdhui'],
            ['cle' => 'id_agent', 'libelle' => 'Agent crédit (CDS 2)', 'type' => 'liste', 'section' => $appel, 'source_liste' => 'agent_credit', 'obligatoire' => true],
            ['cle' => 'num_cni', 'libelle' => 'Numéro de la pièce d\'identité', 'type' => 'texte', 'section' => $appel, 'defaut' => 'client.kyc.num_piece_id',
                'aide' => 'Repris du KYC du client.'],
            $liste(\App\Models\CreditScoring::CHAMP_APPEL, 'Statut de l\'appel', $appel,
                [1 => 'Joint', 2 => 'Injoignable', 3 => 'Ne décroche pas', 4 => 'Pas encore appelé'], [1, 2, 3, 4], 'boutons'),
            $ouiNon('q2_cs', 'Pouvez-vous nous accorder un peu de temps svp ?', $appel),
            ['cle' => 'q3_cs', 'libelle' => 'Contact 1', 'type' => 'texte', 'section' => $appel],
            ['cle' => 'q4_cs', 'libelle' => 'Contact 2', 'type' => 'texte', 'section' => $appel],
            $ouiNon('q5_cs', 'Le client utilise-t-il le même numéro que celui fourni au CDS 1 ?', $appel),
            ['cle' => 'heure_cs', 'libelle' => 'Heure du CDS 2', 'type' => 'texte', 'section' => $appel, 'visible' => false],

            // Activité et terrain
            $liste('q1_cs', 'Pour quelle activité souhaitez-vous utiliser la pompe ?', $activite,
                [1 => 'Agriculture', 2 => 'Usage domestique', 3 => 'Usage communautaire', 4 => 'Élevage'], [1, 2, 3, 4], 'boutons'),
            $liste('q6_cs', 'Quelle est votre principale activité (emploi) ?', $activite,
                [1 => 'Agriculteur', 3 => 'Commerçant', 5 => 'Employé du public'], range(1, 8)),
            $nombre('q7_cs', 'Depuis combien de temps exercez-vous cette activité (emploi) ? (années)', $activite),
            $ouiNon('q8_cs', 'Êtes-vous propriétaire du terrain sur lequel sera installée la pompe ?', $activite),
            $nombre('q9_cs', 'Quelle est la superficie de votre terrain ? (ha)', $activite),
            $ouiNon('q10_cs', 'Y a-t-il un conflit foncier sur votre site ?', $activite),

            // Kit et paiement
            // Les codes d'origine sont les numéros des produits (11 = KOMDOGO, 12 = NIKLA…) : liste des produits
            ['cle' => 'q12_cs', 'libelle' => 'Pompe proposée', 'type' => 'liste', 'section' => $kit, 'source_liste' => 'produit'],
            // Plus posée : le deposit est repris automatiquement de la base des ventes (CreditScoring::saving)
            $nombre('q11_cs', 'Combien avez-vous payé comme dépôt initial ? (FCFA)', $kit, ['visible' => false]),
            $nombre('q13_cs', 'Connaissez-vous le montant à payer par mois ? (FCFA)', $kit),
            $nombre('q14_cs', 'Quel est le montant total du kit ? (FCFA)', $kit),

            // Élevage et agriculture
            $liste('q15_cs', 'Que faites-vous comme élevage ?', $elevage, [], [1, 2, 3, 4]),
            ['cle' => 'q15_cs_autre', 'libelle' => 'Si autre (élevage)', 'type' => 'texte', 'section' => $elevage],
            $liste('q16_cs', 'Que faites-vous comme agriculture ?', $elevage, [9 => 'Hévéa'], [1, 2, 3, 4, 7, 9, 10, 11, 12, 13, 15, 16, 21, 24, 26]),
            $nombre('q17_cs', 'Combien de cycles de ventes avez-vous par année ?', $elevage),
            $nombre('q18_cs', 'Combien de têtes vendez-vous par mois ?', $elevage),
            $nombre('q24_cs', 'Depuis combien de temps pratiquez-vous cette activité ? (années)', $elevage),
            $nombre('q25_cs', 'Combien d\'activités d\'élevage avez-vous faites ?', $elevage),

            // Eau et énergie
            $liste('id_src_eau', 'Type de source d\'eau', $eau, $sourcesEau, range(1, 9)),
            $liste('q19_cs', 'Quelle est votre source d\'eau ?', $eau, $sourcesEau, range(1, 9)),
            $nombre('q20_cs', 'De quelle quantité d\'eau avez-vous besoin par jour ? (L)', $eau),
            $ouiNon('q21_cs', 'Avez-vous un réservoir ?', $eau),
            $nombre('q22_cs', 'Quelle est la capacité du réservoir d\'eau ? (L)', $eau, $si('q21_cs', '1')),
            $ouiNon('q23_cs', 'Avez-vous un branchement électrique ?', $eau),

            // Revenus et prêts
            $nombre('q26_cs', 'Quel est votre revenu moyen ? (FCFA)', $revenus),
            $liste('q27_cs', 'Quelle est la périodicité de votre revenu ?', $revenus, [4 => 'Mensuel'], range(1, 8)),
            $liste('q28_cs', 'Avez-vous une autre source de revenus, laquelle ?', $revenus, [3 => 'Activité commerciale'], range(1, 12)),
            ['cle' => 'q28_cs_autre', 'libelle' => 'Si autre (revenu)', 'type' => 'texte', 'section' => $revenus],
            $nombre('q29_cs', 'De combien d\'argent disposez-vous après avoir payé toutes les dépenses mensuelles ? (FCFA)', $revenus),
            $ouiNon('q30_cs', 'Avez-vous des prêts en cours actuellement ?', $revenus),
            $nombre('q31_cs', 'Combien de prêts avez-vous contractés au cours des deux dernières années ?', $revenus),
            $nombre('q32_cs', 'Quel est le montant total des prêts en cours ? (FCFA)', $revenus, $si('q30_cs', '1')),
            $nombre('q33_cs', 'En combien de mois finiriez-vous de rembourser ce(s) prêt(s) ?', $revenus, $si('q30_cs', '1')),
            $ouiNon('q34_cs', 'Avez-vous un compte MTN Mobile Money ?', $revenus),
            $ouiNon('q35_cs', 'Allez-vous faire les paiements via votre compte MTN MoMo ?', $revenus),

            // Stabilité
            ['cle' => 'q37_cs', 'libelle' => 'Dans quelle localité résidez-vous ?', 'type' => 'texte', 'section' => $stabilite],
            $nombre('q38_cs', 'Depuis combien de temps vivez-vous au même endroit ? (années)', $stabilite),
            $nombre('q39_cs', 'Depuis combien de temps avez-vous le même numéro de téléphone ? (années)', $stabilite),
            $liste('q40_cs', 'Quelle est la meilleure couverture réseau mobile dans votre localité ?', $stabilite, [1 => 'Orange', 2 => 'Moov'], [1, 2, 3, 4], 'boutons'),

            // Décision du crédit
            $liste('q36_cs', 'Évaluation globale du risque par le Team lead du crédit', $decision,
                [1 => 'Risque très faible', 2 => 'Risque faible'], [1, 2, 3, 4], 'boutons'),
            $liste(\App\Models\CreditScoring::CHAMP_DECISION, 'Statut du CDS 2', $decision, \App\Models\CreditScoring::DECISIONS, range(1, 8), 'liste',
                ['obligatoire' => true, 'aide' => '« Validé » termine la validation crédit du dossier.']),
            ['cle' => 'q43_cs', 'libelle' => 'Date de validation', 'type' => 'date', 'section' => $decision, 'defaut' => 'aujourdhui']
                + $si(\App\Models\CreditScoring::CHAMP_DECISION, \App\Models\CreditScoring::DECISION_VALIDEE),
            $ouiNon('q44_cs', 'Le kit sera-t-il réceptionné par vous-même ?', $decision),
            ['cle' => 'q45_cs', 'libelle' => 'Si non, quel est le nom du réceptionnaire désigné ?', 'type' => 'texte', 'section' => $decision] + $si('q44_cs', '0'),
            $ouiNon('q46_cs', 'En cas d\'indisponibilité, autorisez-vous votre bénéficiaire à signer le contrat en votre nom lors de la livraison ?', $decision),
            ['cle' => \App\Models\CreditScoring::CHAMP_COMMENTAIRE, 'libelle' => 'Commentaire de l\'agent crédit', 'type' => 'zone_texte', 'section' => $decision, 'pleine_largeur' => true],

            // Colonnes d'origine sans question dans le formulaire AppSheet : conservées, masquées
            ['cle' => 'id_activite', 'libelle' => 'Activité (code d\'origine)', 'type' => 'nombre', 'section' => 'Informations techniques', 'visible' => false],
            ['cle' => 'id_periodicite', 'libelle' => 'Périodicité (code d\'origine)', 'type' => 'nombre', 'section' => 'Informations techniques', 'visible' => false],
        ]);

        // Colonnes texte en base (q…_cs) : type de réponse modifiable (sauf la décision, qui pilote le parcours)
        return array_map(fn (array $champ) => $champ + [
            'type_modifiable' => str_starts_with($champ['cle'], 'q') && $champ['cle'] !== \App\Models\CreditScoring::CHAMP_DECISION,
            'formule' => null,
        ], $champs);
    }

    public static function sectionsCreditScoring(): array
    {
        return [
            ['nom' => 'Appel', 'ordre' => 10, 'description' => 'Appel du client par l\'agent crédit (CDS 2).', 'conditions' => null],
            ['nom' => 'Activité et terrain', 'ordre' => 20, 'description' => null, 'conditions' => null],
            ['nom' => 'Kit et paiement', 'ordre' => 30, 'description' => null, 'conditions' => null],
            ['nom' => 'Élevage et agriculture', 'ordre' => 40, 'description' => null, 'conditions' => null],
            ['nom' => 'Eau et énergie', 'ordre' => 50, 'description' => null, 'conditions' => null],
            ['nom' => 'Revenus et prêts', 'ordre' => 60, 'description' => null, 'conditions' => null],
            ['nom' => 'Stabilité', 'ordre' => 70, 'description' => null, 'conditions' => null],
            ['nom' => 'Décision du crédit', 'ordre' => 80, 'description' => null, 'conditions' => null],
            ['nom' => 'Informations techniques', 'ordre' => 90, 'description' => null, 'conditions' => null],
        ];
    }

    /** @param  array<string, string>  $choix  [valeur => libellé] */
    private static function choix(array $choix): array
    {
        return collect($choix)->map(fn ($libelle, $valeur) => ['valeur' => (string) $valeur, 'libelle' => $libelle, 'parent' => null])->values()->all();
    }

    private static function options(array $libelles): array
    {
        return array_map(fn ($libelle) => ['valeur' => $libelle, 'libelle' => $libelle, 'parent' => null], $libelles);
    }

    /** Valeurs par défaut et numérotation de l'ordre (10, 20, 30…). */
    private static function normaliser(string $formulaire, array $champs): array
    {
        return array_map(fn (array $champ, int $index) => $champ + [
            'formulaire' => $formulaire,
            'standard' => true,
            'stockage' => 'colonne',
            'ordre' => ($index + 1) * 10,
            'visible' => true,
            'obligatoire' => false,
            'verrouille' => false,
            'pleine_largeur' => false,
            'aide' => null,
            'defaut' => null,
            'source_liste' => null,
            'options' => null,
            'liste_parent' => null,
            'liste_parent_colonne' => null,
            'conditions' => null,
        ], $champs, array_keys($champs));
    }

    /** Lignes prêtes pour un insert en base (options et conditions encodées en JSON). */
    public static function pourInsertion(array $champs): array
    {
        return array_map(fn (array $champ) => array_merge($champ, [
            'options' => $champ['options'] !== null ? json_encode($champ['options'], JSON_UNESCAPED_UNICODE) : null,
            'conditions' => $champ['conditions'] !== null ? json_encode($champ['conditions'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]), $champs);
    }
}
