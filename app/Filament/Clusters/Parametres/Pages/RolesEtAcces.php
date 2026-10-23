<?php

namespace App\Filament\Clusters\Parametres\Pages;

use App\Filament\Clusters\Parametres\ParametresCluster;
use App\Filament\Clusters\Parametres\Resources\Pages as PagesAcces;
use App\Filament\Clusters\Parametres\Resources\RoleResource;
use App\Filament\Clusters\Parametres\Resources\UtilisateurResource;
use App\Models\Role;
use App\Models\User;
use App\Support\Acces;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Paramètres > Rôles et accès : un seul onglet qui regroupe la vue d'ensemble des accès,
 * les utilisateurs, les rôles et la matrice des permissions (rôles × modules).
 * Avant l'installation des tables d'accès, la page explique les deux commandes à lancer.
 */
class RolesEtAcces extends Page
{
    protected static ?string $cluster = ParametresCluster::class;

    protected static ?string $slug = 'roles-et-acces';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Rôles et accès';

    protected static ?int $navigationSort = 49;

    protected string $view = 'filament.pages.roles-et-acces';

    #[Url]
    public string $onglet = 'apercu';

    /** Pages des ressources Utilisateurs et Rôles qui affichent la même barre d'onglets. */
    public const PAGES_LIEES = [
        PagesAcces\ListeUtilisateurs::class => 'utilisateurs',
        PagesAcces\CreerUtilisateur::class => 'utilisateurs',
        PagesAcces\ModifierUtilisateur::class => 'utilisateurs',
        PagesAcces\ListeRoles::class => 'roles',
        PagesAcces\CreerRole::class => 'roles',
        PagesAcces\ModifierRole::class => 'roles',
    ];

    /** Avant l'installation : visible (tout est permis). Ensuite : permission acces.voir. */
    public static function canAccess(): bool
    {
        return ! Acces::installe() || Acces::peut('acces.voir');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Rôles et accès';
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getSubNavigation(): array
    {
        return [];
    }

    public function mount(): void
    {
        if (! in_array($this->onglet, ['apercu', 'matrice'], true)) {
            $this->onglet = 'apercu';
        }
    }

    public function estInstalle(): bool
    {
        return Acces::installe();
    }

    /**
     * Barre d'onglets commune (page + écrans Utilisateurs / Rôles).
     *
     * @return array<string, array{libelle: string, url: string, icone: mixed, badge: int|null}>
     */
    public static function onglets(): array
    {
        $installe = Acces::installe();

        $onglets = [
            'apercu' => ['libelle' => 'Vue d\'ensemble', 'url' => static::getUrl(), 'icone' => Heroicon::OutlinedSquares2x2, 'badge' => null],
        ];

        if ($installe) {
            $onglets['utilisateurs'] = ['libelle' => 'Utilisateurs', 'url' => UtilisateurResource::getUrl(), 'icone' => Heroicon::OutlinedUsers,
                'badge' => rescue(fn () => User::count(), null, false)];
            $onglets['roles'] = ['libelle' => 'Rôles', 'url' => RoleResource::getUrl(), 'icone' => Heroicon::OutlinedIdentification,
                'badge' => rescue(fn () => Role::count(), null, false)];
            $onglets['permissions'] = ['libelle' => 'Permissions', 'url' => PermissionsRoles::getUrl(), 'icone' => Heroicon::OutlinedKey, 'badge' => null];
            $onglets['matrice'] = ['libelle' => 'Matrice des accès', 'url' => static::getUrl(['onglet' => 'matrice']), 'icone' => Heroicon::OutlinedTableCells, 'badge' => null];
        }

        return $onglets;
    }

    /** @return array<int, array{valeur: int, libelle: string, icone: mixed, ton: string, href: string|null}> */
    public function getIndicateurs(): array
    {
        $actifs = User::where('actif', true)->count();
        $sansRole = User::whereDoesntHave('roles')->count();
        $inactifs = User::where('actif', false)->count();

        return [
            ['valeur' => $actifs, 'libelle' => 'Utilisateurs actifs', 'icone' => Heroicon::OutlinedUsers, 'ton' => 'vert',
                'href' => UtilisateurResource::getUrl(parameters: ['tab' => 'actifs'])],
            ['valeur' => $sansRole, 'libelle' => 'Comptes sans rôle', 'icone' => Heroicon::OutlinedExclamationTriangle, 'ton' => $sansRole ? 'orange' : 'gris',
                'href' => UtilisateurResource::getUrl(parameters: ['tab' => 'sans_role'])],
            ['valeur' => Role::count(), 'libelle' => 'Rôles définis', 'icone' => Heroicon::OutlinedKey, 'ton' => 'violet',
                'href' => RoleResource::getUrl()],
            ['valeur' => $inactifs, 'libelle' => 'Comptes désactivés', 'icone' => Heroicon::OutlinedPauseCircle, 'ton' => 'gris',
                'href' => UtilisateurResource::getUrl(parameters: ['tab' => 'inactifs'])],
        ];
    }

    /** Rôles avec leurs compteurs, pour les cartes et la matrice. */
    public function getRoles()
    {
        return Role::query()->withCount(['permissions', 'utilisateurs'])->with('permissions:id,role_id,permission')
            ->orderByDesc('systeme')->orderBy('nom')->get();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    public function getDernieresConnexions()
    {
        return User::query()->with('roles:id,nom,couleur')->whereNotNull('derniere_connexion')
            ->orderByDesc('derniere_connexion')->limit(6)->get();
    }

    public function totalPermissions(): int
    {
        return count(Acces::toutesLesPermissions());
    }

    public function peutModifier(): bool
    {
        return Acces::peut('acces.modifier');
    }

    public static function ton(?string $couleur): string
    {
        return in_array($couleur, array_keys(Acces::COULEURS), true) ? $couleur : 'violet';
    }
}
