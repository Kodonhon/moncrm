<?php

namespace App\Providers\Filament;

use App\Filament\Resources\Clients\ClientResource;
use App\Http\Middleware\ConnexionAutomatique;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName('CRM')
            // Logo temporaire (public/images/logo-temporaire*.svg), à remplacer par le logo définitif
            ->brandLogo(fn () => asset('images/logo-temporaire.svg'))
            ->brandLogoHeight('2.4rem')
            ->favicon(fn () => asset('images/logo-temporaire-icone.svg'))
            ->login()
            // Cloche des notifications (dossier à valider, à scorer, à préparer…), actualisée toutes les 30 s.
            // Seulement une fois la table « notifications » créée (script SQL) : sinon chaque page planterait.
            ->databaseNotifications(fn () => \Illuminate\Support\Facades\Cache::remember('table-notifications-disponible', now()->addMinutes(10),
                fn () => \Illuminate\Support\Facades\Schema::hasTable('notifications')))
            // Une vérification par minute suffit (chaque vérification interroge la base distante)
            ->databaseNotificationsPolling('60s')
            // « Mon profil » : nom, e-mail, mot de passe, photo, double authentification (Breezy, si installé)
            ->plugins(array_filter([
                class_exists(\Jeffgreco13\FilamentBreezy\BreezyCore::class)
                    ? \Jeffgreco13\FilamentBreezy\BreezyCore::make()
                        ->myProfile(
                            shouldRegisterUserMenu: true,
                            userMenuLabel: 'Mon profil',
                            hasAvatars: true,
                            slug: 'mon-profil',
                        )
                        ->enableTwoFactorAuthentication(force: false)
                    : null,
            ]))
            ->homeUrl(fn () => ClientResource::getUrl())
            ->darkMode(false)
            // Menu à gauche façon AppSheet : icône + libellé, entrée active surlignée ; repliable en icônes (☰)
            ->sidebarCollapsibleOnDesktop()
            // Menus déroulants (comme SunCulture) : un titre de groupe gris avec chevron, ses écrans en dessous.
            // L'icône de chaque groupe est ajoutée en CSS (Filament n'accepte pas d'icône sur un groupe ET ses entrées).
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make('Ventes'),
                \Filament\Navigation\NavigationGroup::make('Crédit'),
                \Filament\Navigation\NavigationGroup::make('Opérations'),
                \Filament\Navigation\NavigationGroup::make('Messages'),
                \Filament\Navigation\NavigationGroup::make('Administration')->collapsed(),
            ])
            ->navigationItems(self::entreesAdministration())
            ->sidebarWidth('16rem')
            ->maxContentWidth(Width::Full)
            ->font('Inter')
            // Pas de recherche globale dans la barre du haut (chaque liste a sa propre recherche)
            ->globalSearch(false)
            ->colors([
                // Charte GREENO (affiche « Cadeaux et invitations ») : vert, violet indigo, orange
                'primary' => Color::hex('#5AAA3C'),
                'gray' => Color::Slate,
                'success' => Color::hex('#4E9F3D'),
                'warning' => Color::hex('#E8752B'),
                'danger' => Color::hex('#D9342B'),
                'info' => Color::hex('#3E3765'),
            ])
            // En-tête commun des écrans de Paramètres (retour à l'Administration, icône, description)
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn (array $scopes) => view('filament.entete-parametre', ['scopes' => $scopes]),
                scopes: \App\Filament\Clusters\Parametres\Pages\Administration::ressources(),
            )
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn (array $scopes) => view('filament.acces.entete', ['scopes' => $scopes]),
                scopes: array_keys(\App\Filament\Clusters\Parametres\Pages\RolesEtAcces::PAGES_LIEES),
            )
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn (array $scopes) => view('filament.messages.entete', ['scopes' => $scopes]),
                scopes: [
                    \App\Filament\Resources\Messages\Pages\ListeModelesSms::class,
                    \App\Filament\Resources\Messages\Pages\CreerModeleSms::class,
                    \App\Filament\Resources\Messages\Pages\ModifierModeleSms::class,
                    \App\Filament\Resources\Messages\Pages\ListeMessagesSms::class,
                    \App\Filament\Pages\ConfigurationSms::class,
                ],
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ConnexionAutomatique::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /** Sous-menu « Administration » : accès directs aux écrans les plus utilisés de Paramètres. */
    private static function entreesAdministration(): array
    {
        $route = fn (string $nom) => fn () => request()->routeIs("filament.admin.parametres.{$nom}");
        $acces = fn (string $permission) => fn () => \App\Support\Acces::installe() && \App\Support\Acces::peut($permission);

        return [
            \Filament\Navigation\NavigationItem::make('Utilisateurs')
                ->group('Administration')->sort(91)
                ->icon(\Filament\Support\Icons\Heroicon::OutlinedUsers)
                ->url(fn () => \App\Filament\Clusters\Parametres\Resources\UtilisateurResource::getUrl())
                ->isActiveWhen($route('resources.utilisateurs.*'))
                ->visible($acces('acces.voir')),
            \Filament\Navigation\NavigationItem::make('Rôles')
                ->group('Administration')->sort(92)
                ->icon(\Filament\Support\Icons\Heroicon::OutlinedIdentification)
                ->url(fn () => \App\Filament\Clusters\Parametres\Resources\RoleResource::getUrl())
                ->isActiveWhen($route('resources.roles.*'))
                ->visible($acces('acces.voir')),
            \Filament\Navigation\NavigationItem::make('Permissions des rôles')
                ->group('Administration')->sort(93)
                ->icon(\Filament\Support\Icons\Heroicon::OutlinedShieldCheck)
                ->url(fn () => \App\Filament\Clusters\Parametres\Pages\PermissionsRoles::getUrl())
                ->isActiveWhen($route('pages.permissions-roles'))
                ->visible($acces('acces.voir')),
            \Filament\Navigation\NavigationItem::make('Agents')
                ->group('Administration')->sort(94)
                ->icon(\Filament\Support\Icons\Heroicon::OutlinedUserCircle)
                ->url(fn () => \App\Filament\Clusters\Parametres\Resources\AgentResource::getUrl())
                ->isActiveWhen($route('resources.agents.*'))
                ->visible($acces('agents.voir')),
        ];
    }
}
