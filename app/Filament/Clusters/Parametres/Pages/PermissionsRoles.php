<?php

namespace App\Filament\Clusters\Parametres\Pages;

use App\Filament\Clusters\Parametres\ParametresCluster;
use App\Models\Role;
use App\Support\Acces;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Paramètres > Rôles et accès > Permissions : on choisit un rôle, puis un module à gauche,
 * et chaque action du module s'active ou se désactive d'un clic (enregistré tout de suite).
 */
class PermissionsRoles extends Page
{
    protected static ?string $cluster = ParametresCluster::class;

    protected static ?string $slug = 'permissions-roles';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Permissions des rôles';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.permissions-roles';

    /** Ce que chaque action permet, en clair. */
    public const DESCRIPTIONS = [
        'voir' => 'Afficher le module dans le menu, ses listes et ses fiches.',
        'creer' => 'Ajouter de nouveaux éléments dans ce module.',
        'modifier' => 'Modifier les éléments existants de ce module.',
        'valider' => 'Valider ou refuser les éléments soumis dans ce module.',
        'exporter' => 'Télécharger les données de ce module (Excel / CSV).',
        'supprimer' => 'Désactiver ou supprimer des éléments de ce module.',
    ];

    #[Url]
    public ?int $role = null;

    #[Url]
    public string $module = '';

    public string $recherche = '';

    public static function canAccess(): bool
    {
        return Acces::installe() && Acces::peut('acces.voir');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Permissions des rôles';
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
        $this->role = Role::query()->whereKey($this->role)->exists()
            ? $this->role
            : Role::query()->orderByDesc('systeme')->orderBy('nom')->value('id');

        if (! array_key_exists($this->module, Acces::MODULES)) {
            $this->module = array_key_first(Acces::MODULES);
        }
    }

    public function getRoleActif(): ?Role
    {
        return $this->role ? Role::query()->with('permissions:id,role_id,permission')->find($this->role) : null;
    }

    /** @return array<int, string> */
    public function getPermissionsDuRole(): array
    {
        $role = $this->getRoleActif();

        return $role?->estAdministrateur() ? Acces::toutesLesPermissions() : ($role?->permissions->pluck('permission')->all() ?? []);
    }

    public function getOptionsRoles(): array
    {
        return Role::query()->orderByDesc('systeme')->orderBy('nom')->pluck('nom', 'id')->all();
    }

    /** Modules filtrés par la recherche : [clé => [libellé, description, actions, icône, accordées]] */
    public function getModules(): array
    {
        $accordees = $this->getPermissionsDuRole();
        $recherche = mb_strtolower(trim($this->recherche));

        return collect(Acces::MODULES)
            ->filter(fn (array $m) => $recherche === '' || str_contains(mb_strtolower($m[0].' '.$m[1]), $recherche))
            ->map(fn (array $m, string $cle) => [
                'libelle' => $m[0],
                'description' => $m[1],
                'actions' => $m[2],
                'icone' => defined(Heroicon::class.'::'.$m[3]) ? constant(Heroicon::class.'::'.$m[3]) : Heroicon::OutlinedSquares2x2,
                'accordees' => count(array_intersect($accordees, array_map(fn ($a) => "{$cle}.{$a}", $m[2]))),
            ])
            ->all();
    }

    public function peutModifier(): bool
    {
        return Acces::peut('acces.modifier') && ! $this->getRoleActif()?->estAdministrateur();
    }

    public function choisirModule(string $module): void
    {
        if (array_key_exists($module, Acces::MODULES)) {
            $this->module = $module;
        }
    }

    public function updatedRole(): void
    {
        $this->mount();
    }

    /** Active ou désactive une action du module affiché. */
    public function basculer(string $action): void
    {
        $permission = "{$this->module}.{$action}";
        if (! $this->peutModifier() || ! in_array($action, Acces::MODULES[$this->module][2] ?? [], true)) {
            return;
        }

        $role = $this->getRoleActif();
        $actuelles = $role->listePermissions();
        $active = ! in_array($permission, $actuelles, true);

        $role->synchroniserPermissions($active
            ? [...$actuelles, $permission]
            : array_values(array_diff($actuelles, [$permission])));

        Notification::make()
            ->title(Acces::libellePermission($permission).($active ? ' activé' : ' désactivé'))
            ->body('Rôle « '.$role->nom.' »')
            ->success()
            ->send();
    }

    /** Toutes les actions du module affiché : activées (true) ou désactivées (false). */
    public function toutBasculer(bool $activer): void
    {
        if (! $this->peutModifier()) {
            return;
        }

        $role = $this->getRoleActif();
        $module = array_map(fn ($a) => "{$this->module}.{$a}", Acces::MODULES[$this->module][2] ?? []);
        $autres = array_values(array_diff($role->listePermissions(), $module));

        $role->synchroniserPermissions($activer ? [...$autres, ...$module] : $autres);

        Notification::make()
            ->title(Acces::MODULES[$this->module][0].' : '.($activer ? 'tout activé' : 'tout désactivé'))
            ->body('Rôle « '.$role->nom.' »')
            ->success()
            ->send();
    }
}
