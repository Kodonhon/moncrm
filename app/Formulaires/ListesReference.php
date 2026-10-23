<?php

namespace App\Formulaires;

use App\Models\Agent;
use App\Models\CanalVente;
use App\Models\Entite;
use App\Models\Genre;
use App\Models\LienPar;
use App\Models\Localite;
use App\Models\Produit;
use App\Models\SiteShop;
use App\Models\SourceClient;
use App\Models\SourceEnergie;
use App\Models\TypeEvalSite;
use App\Models\TypePiece;
use App\Models\TypeVente;
use App\Models\UsageKit;
use App\Models\Ville;
use App\Support\CacheReferences;

/**
 * Tables de référence utilisables comme source d'une liste déroulante dans un formulaire,
 * avec les colonnes par lesquelles une liste peut être filtrée selon la réponse à une autre question.
 */
class ListesReference
{
    public const PERSONNALISEE = 'personnalisee';

    public const SOURCES = [
        'agent' => ['libelle' => 'Agents', 'modele' => Agent::class, 'filtres' => ['id_site_shop' => 'Site / shop']],
        // Agents dont le rôle est « crédit » (s'il n'y en a aucun : tous les agents)
        'agent_credit' => ['libelle' => 'Agents crédit', 'modele' => Agent::class, 'filtres' => ['id_site_shop' => 'Site / shop'], 'fixe' => ['role_agent' => 'credit']],
        'canal_vente' => ['libelle' => 'Canaux de vente', 'modele' => CanalVente::class, 'filtres' => ['id_src_client' => 'Source', 'id_entite' => 'Entité']],
        'localite_commune' => ['libelle' => 'Localités', 'modele' => Localite::class, 'filtres' => ['id_ville' => 'Ville', 'id_site_shop' => 'Site / shop']],
        'ville' => ['libelle' => 'Villes', 'modele' => Ville::class, 'filtres' => []],
        'site_shop' => ['libelle' => 'Sites & shops', 'modele' => SiteShop::class, 'filtres' => []],
        'src_client' => ['libelle' => 'Sources client', 'modele' => SourceClient::class, 'filtres' => []],
        'entite' => ['libelle' => 'Entités', 'modele' => Entite::class, 'filtres' => []],
        'genre' => ['libelle' => 'Genres', 'modele' => Genre::class, 'filtres' => []],
        'type_piece_id' => ['libelle' => 'Types de pièce', 'modele' => TypePiece::class, 'filtres' => []],
        'type_vente' => ['libelle' => 'Types de vente', 'modele' => TypeVente::class, 'filtres' => []],
        'lien_par' => ['libelle' => 'Liens de parenté', 'modele' => LienPar::class, 'filtres' => []],
        'type_eval_site' => ['libelle' => 'Types d\'évaluation', 'modele' => TypeEvalSite::class, 'filtres' => ['id_entite' => 'Entité']],
        'produit' => ['libelle' => 'Produits', 'modele' => Produit::class, 'filtres' => []],
        'usage_kit' => ['libelle' => 'Usages du kit', 'modele' => UsageKit::class, 'filtres' => []],
        'src_energie' => ['libelle' => 'Sources d\'énergie', 'modele' => SourceEnergie::class, 'filtres' => []],
    ];

    /**
     * Valeur « pour tous » d'une colonne de filtre : l'entité 0 (All, mutualisation totale)
     * est proposée quelle que soit l'entité choisie, et choisir All affiche tout.
     */
    public const VALEURS_UNIVERSELLES = ['id_entite' => '0'];

    /** Choix proposés dans Paramètres pour la source d'une liste. */
    public static function choixSources(): array
    {
        return collect(self::SOURCES)->map(fn ($source) => $source['libelle'])
            ->prepend('Liste personnalisée (choix saisis à la main)', self::PERSONNALISEE)
            ->all();
    }

    public static function filtresPossibles(?string $source): array
    {
        return self::SOURCES[$source]['filtres'] ?? [];
    }

    /** Libellé d'une valeur (ex. n° de localité → « BEDIALA — DALOA »), pour l'affichage d'une fiche. */
    public static function libelle(string $source, mixed $valeur): ?string
    {
        $modele = self::SOURCES[$source]['modele'] ?? null;

        if (! $modele || blank($valeur)) {
            return null;
        }

        $element = CacheReferences::trouver($modele, $valeur);

        if (! $element) {
            return "n° {$valeur} (inconnu)";
        }

        if ($element instanceof Localite) {
            return collect([
                $element->lib_localite,
                $element->id_ville ? CacheReferences::libelle(Ville::class, $element->id_ville) : null,
                $element->id_site_shop ? CacheReferences::libelle(SiteShop::class, $element->id_site_shop) : null,
            ])->filter()->implode(' · ');
        }

        return $element->libelle;
    }

    /**
     * Options [id => libellé] d'une table de référence : éléments actifs (+ la valeur actuelle),
     * éventuellement restreints à ceux dont $colonneFiltre vaut $valeurFiltre.
     */
    public static function options(string $source, mixed $valeurActuelle = null, ?string $colonneFiltre = null, mixed $valeurFiltre = null): array
    {
        $modele = self::SOURCES[$source]['modele'] ?? null;
        if (! $modele) {
            return [];
        }

        $universelle = $colonneFiltre ? (self::VALEURS_UNIVERSELLES[$colonneFiltre] ?? null) : null;
        // Filtrer sur la valeur « pour tous » (ex. entité All) revient à ne pas filtrer
        $filtrer = $colonneFiltre && filled($valeurFiltre) && (string) $valeurFiltre !== $universelle;

        // Aucun élément ne correspond (ex. entité inconnue) : on propose la liste complète plutôt qu'une liste vide
        if ($filtrer && ! CacheReferences::tous($modele)->contains(fn ($element) => $element->{$modele::COLONNE_VISIBILITE}
            && ((string) $element->{$colonneFiltre} === (string) $valeurFiltre
                || ($universelle !== null && (string) $element->{$colonneFiltre} === $universelle)))) {
            $filtrer = false;
        }

        // Restriction propre à la source (ex. agents crédit) ; ignorée si elle ne laisse aucun choix
        $fixes = self::SOURCES[$source]['fixe'] ?? [];
        $respecteFixes = function ($element) use (&$fixes) {
            return collect($fixes)->every(fn ($valeur, $colonne) => (string) $element->{$colonne} === (string) $valeur);
        };
        if ($fixes && ! CacheReferences::tous($modele)->contains(fn ($element) => $element->{$modele::COLONNE_VISIBILITE} && $respecteFixes($element))) {
            $fixes = [];
        }

        return CacheReferences::tous($modele)
            ->filter(fn ($element) => ($element->{$modele::COLONNE_VISIBILITE}
                    && $respecteFixes($element)
                    && (! $filtrer
                        || (string) $element->{$colonneFiltre} === (string) $valeurFiltre
                        || ($universelle !== null && (string) $element->{$colonneFiltre} === $universelle)))
                || (filled($valeurActuelle) && ! is_array($valeurActuelle) && (string) $element->getKey() === (string) $valeurActuelle))
            ->sortBy($modele::COLONNE_LIBELLE, SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn ($element) => [$element->getKey() => match (true) {
                // Localités : on ajoute la ville pour distinguer les homonymes
                $element instanceof Localite => $element->lib_localite
                    .($element->id_ville ? ' — '.CacheReferences::libelle(Ville::class, $element->id_ville) : ''),
                default => $element->{$modele::COLONNE_LIBELLE},
            }])
            ->all();
    }
}
