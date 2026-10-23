<?php

namespace App\Filament\Clusters\Parametres\Pages;

use App\Filament\Clusters\Parametres\ParametresCluster;
use App\Filament\Clusters\Parametres\Resources;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Paramètres > Administration : page d'accueil des paramètres, en cartes regroupées par thème,
 * avec une recherche. C'est la première page du cluster : le menu « Paramètres » y mène directement.
 */
class Administration extends Page
{
    protected static ?string $cluster = ParametresCluster::class;

    protected static ?string $slug = 'administration';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Vue d\'ensemble';

    protected static ?int $navigationSort = -100;

    protected string $view = 'filament.pages.administration';

    /** Thèmes et cartes : [ressource, description]. Le titre et l'icône viennent de la ressource. */
    public const GROUPES = [
        'Organisation' => [
            [Resources\AgentResource::class, 'Fiches agents, rôles, supérieurs, contacts et dates d\'entrée.'],
            [Resources\EntiteResource::class, 'Entités du groupe (Greeno, Tevia…) utilisées sur les clients et les canaux.'],
            [Resources\SiteShopResource::class, 'Sites et shops de rattachement des agents et des localités.'],
            [Resources\VilleResource::class, 'Villes utilisées pour regrouper les localités.'],
            [Resources\LocaliteResource::class, 'Localités et communes, avec leur position GPS.'],
        ],
        'Formulaires' => [
            [Resources\FormulaireChampResource::class, 'Questions, sections et ordre du formulaire de création client.'],
            [Resources\FormulaireKycResource::class, 'Questions et pièces demandées lors du KYC.'],
            [Resources\FormulaireEvalSiteResource::class, 'Questions de l\'évaluation du site (TDH, SHS, SST).'],
            [Resources\FormulaireCreditScoringResource::class, 'Questions et pondérations du credit scoring.'],
        ],
        'Ventes & produits' => [
            [Resources\ProduitResource::class, 'Catalogue des produits proposés aux clients.'],
            [Resources\UsageKitResource::class, 'Usages possibles d\'un kit (irrigation, maison…).'],
            [Resources\TypeVenteResource::class, 'Modes de vente : comptant, PAYG…'],
            [Resources\CanalVenteResource::class, 'Canaux par lesquels arrivent les clients.'],
            [Resources\SourceClientResource::class, 'Sources des prospects et des clients.'],
            [Resources\SourceEnergieResource::class, 'Sources d\'énergie actuelles des clients.'],
            [Resources\TypeEvalSiteResource::class, 'Types d\'évaluation du site, par entité.'],
        ],
        'Sécurité et accès' => [
            [RolesEtAcces::class, 'Utilisateurs, rôles, permissions par module et données visibles : tout au même endroit.'],
        ],
        'Référentiels clients' => [
            [Resources\GenreResource::class, 'Genres proposés sur les fiches.'],
            [Resources\TypePieceResource::class, 'Pièces d\'identité acceptées pour le KYC.'],
            [Resources\LienParResource::class, 'Liens de parenté de la personne à contacter.'],
        ],
    ];

    /** Couleur de l'icône ronde de chaque thème (charte GREENO). */
    public const TONS = [
        'Organisation' => 'violet',
        'Formulaires' => 'vert',
        'Ventes & produits' => 'orange',
        'Référentiels clients' => 'violet',
        'Sécurité et accès' => 'rouge',
    ];

    /** Visible dès qu'un des écrans de paramètres est accessible. */
    public static function canAccess(): bool
    {
        return collect(self::ressources())->contains(fn ($r) => class_exists($r) && $r::canAccess());
    }

    public function getTitle(): string|Htmlable
    {
        return 'Administration';
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    /** Page d'accueil : pas de menu secondaire, les cartes en tiennent lieu. */
    public function getSubNavigation(): array
    {
        return [];
    }

    /** @return array<string, array<int, array{titre: string, description: string, url: string, icone: mixed}>> */
    public function getGroupes(): array
    {
        $groupes = [];

        foreach (self::GROUPES as $groupe => $cartes) {
            foreach ($cartes as [$ressource, $description]) {
                if (! class_exists($ressource) || ! $ressource::canAccess()) {
                    continue;
                }

                $groupes[$groupe][] = [
                    'ton' => self::TONS[$groupe] ?? 'vert',
                    'titre' => $ressource::getNavigationLabel(),
                    'description' => $description,
                    'url' => $ressource::getUrl(),
                    'icone' => $ressource::getNavigationIcon() ?? Heroicon::OutlinedCog6Tooth,
                ];
            }
        }

        return $groupes;
    }

    /**
     * Carte d'un écran de paramètres (titre, description, icône, thème), pour l'en-tête de cet écran.
     *
     * @return array{groupe: string, ton: string, titre: string, description: string, icone: mixed}|null
     */
    public static function carte(string $ressource): ?array
    {
        foreach (self::GROUPES as $groupe => $cartes) {
            foreach ($cartes as [$classe, $description]) {
                if ($classe === $ressource && class_exists($classe)) {
                    return [
                        'groupe' => $groupe,
                        'ton' => self::TONS[$groupe] ?? 'vert',
                        'titre' => $classe::getNavigationLabel(),
                        'description' => $description,
                        'icone' => $classe::getNavigationIcon() ?? Heroicon::OutlinedCog6Tooth,
                    ];
                }
            }
        }

        return null;
    }

    /** @return array<int, class-string> écrans affichés en cartes (pour l'en-tête commun) */
    public static function ressources(): array
    {
        return collect(self::GROUPES)->flatten(1)->pluck(0)->all();
    }

    public function getNombreCartes(): int
    {
        return collect($this->getGroupes())->flatten(1)->count();
    }
}
