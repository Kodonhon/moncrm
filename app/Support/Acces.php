<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles et accès du CRM harmonisé.
 *
 * - Une permission = « module.action » (ex. clients.voir, kyc.valider). Catalogue ci-dessous.
 * - Un utilisateur a un ou plusieurs rôles ; ses permissions sont l'union de celles de ses rôles.
 * - Le périmètre (quelles données il voit) est le plus large de ses rôles, éventuellement limité
 *   aux entités cochées sur sa fiche utilisateur.
 * - Tant que les tables d'accès ne sont pas installées (php artisan crm:installer-acces), tout est permis :
 *   le CRM continue de fonctionner comme avant.
 */
class Acces
{
    public const ACTIONS = [
        'voir' => 'Consulter',
        'creer' => 'Créer',
        'modifier' => 'Modifier',
        'valider' => 'Valider',
        'exporter' => 'Exporter',
        'supprimer' => 'Supprimer / désactiver',
        'envoyer' => 'Envoyer',
        'configurer' => 'Configurer',
    ];

    /** module => [libellé, description, actions possibles, icône Heroicon] */
    public const MODULES = [
        'tableau_de_bord' => ['Tableau de bord', 'Indicateurs et graphiques', ['voir'], 'OutlinedChartBar'],
        'clients' => ['Clients', 'Liste et fiche client', ['voir', 'creer', 'modifier', 'exporter', 'supprimer'], 'OutlinedUserGroup'],
        'kyc' => ['KYC', 'Identité, pièces, personne à contacter', ['voir', 'creer', 'modifier', 'valider'], 'OutlinedIdentification'],
        'evaluations' => ['Évaluations / TDH', 'Dimensionnement et évaluation du site', ['voir', 'creer', 'modifier', 'valider'], 'OutlinedBeaker'],
        'contrats' => ['Contrats', 'Création des contrats', ['voir', 'creer', 'modifier', 'exporter'], 'OutlinedDocumentText'],
        'credit_scoring' => ['Credit scoring', 'Appel et décision du crédit', ['voir', 'creer', 'modifier', 'valider'], 'OutlinedScale'],
        'preparation_installation' => ['Préparation installation', 'Appels du CX avant l\'installation', ['voir', 'creer'], 'OutlinedWrenchScrewdriver'],
        'assignation_kits' => ['Assignation des kits', 'Clients prêts pour l\'installation, kits à assigner (shop managers)', ['voir', 'modifier'], 'OutlinedCube'],
        'installations' => ['Installations', 'Rapports d\'installation des techniciens', ['voir', 'creer'], 'OutlinedHomeModern'],
        'deposits' => ['Deposit', 'Paiements des contrats UPYA (base des ventes)', ['voir'], 'OutlinedBanknotes'],
        'messages' => ['Messages SMS', 'Modèles, envoi de SMS (LeTexto) et configuration de l\'API', ['voir', 'creer', 'modifier', 'valider', 'envoyer', 'configurer'], 'OutlinedChatBubbleLeftRight'],
        'agents' => ['Gestion des agents', 'Fiches agents et hiérarchie', ['voir', 'creer', 'modifier', 'supprimer'], 'OutlinedUserCircle'],
        'parametres' => ['Paramètres', 'Référentiels et formulaires', ['voir', 'modifier'], 'OutlinedCog6Tooth'],
        'acces' => ['Rôles et accès', 'Utilisateurs, rôles et permissions', ['voir', 'modifier'], 'OutlinedShieldCheck'],
    ];

    /** Périmètre des données : du plus large au plus restreint. */
    public const PERIMETRES = [
        'tout' => ['Toutes les données', 'Voit tous les clients, toutes entités confondues.'],
        'entite' => ['Son entité', 'Voit les clients de son entité (celle de son agent, ou les entités cochées sur sa fiche).'],
        'shop' => ['Son shop', 'Voit les clients des localités rattachées au shop de son agent (shop managers).'],
        'equipe' => ['Son équipe', 'Voit ses clients et ceux des agents qu\'il supervise (hiérarchie des agents).'],
        'personnel' => ['Ses clients', 'Voit uniquement les clients qu\'il a enregistrés.'],
    ];

    public const COULEURS = ['violet' => 'Violet', 'vert' => 'Vert', 'orange' => 'Orange', 'rouge' => 'Rouge', 'gris' => 'Gris'];

    /** Rôles créés à l'installation : code => [nom, description, couleur, périmètre, permissions ('*' = tout)] */
    public const ROLES_PAR_DEFAUT = [
        'administrateur' => ['Administrateur', 'Accès complet, y compris la gestion des rôles et des utilisateurs.', 'rouge', 'tout', ['*']],
        'direction' => ['Direction', 'Consultation de toutes les données et exports.', 'violet', 'tout', [
            'tableau_de_bord.voir', 'clients.voir', 'clients.exporter', 'kyc.voir', 'evaluations.voir', 'contrats.voir',
            'credit_scoring.voir', 'preparation_installation.voir', 'assignation_kits.voir', 'installations.voir', 'deposits.voir', 'agents.voir',
        ]],
        'responsable_commercial' => ['Responsable commercial', 'Pilote son équipe : clients, KYC, évaluations et contrats.', 'vert', 'equipe', [
            'tableau_de_bord.voir', 'clients.voir', 'clients.creer', 'clients.modifier', 'clients.exporter',
            'kyc.voir', 'kyc.creer', 'kyc.modifier', 'evaluations.voir', 'evaluations.creer', 'evaluations.modifier', 'evaluations.valider',
            'contrats.voir', 'contrats.creer', 'credit_scoring.voir', 'preparation_installation.voir', 'installations.voir', 'agents.voir',
        ]],
        'commercial_terrain' => ['Commercial terrain', 'Enregistre ses prospects, leur KYC et leurs évaluations.', 'vert', 'personnel', [
            'clients.voir', 'clients.creer', 'clients.modifier', 'kyc.voir', 'kyc.creer', 'kyc.modifier',
            'evaluations.voir', 'evaluations.creer', 'evaluations.modifier', 'contrats.voir',
        ]],
        'agent_credit' => ['Agent crédit', 'Valide les KYC et réalise le credit scoring.', 'orange', 'entite', [
            'clients.voir', 'kyc.voir', 'kyc.valider', 'evaluations.voir', 'contrats.voir', 'contrats.creer',
            'credit_scoring.voir', 'credit_scoring.creer', 'credit_scoring.modifier', 'credit_scoring.valider',
            'preparation_installation.voir',
        ]],
        'agent_cx' => ['Agent CX', 'Appelle les clients validés pour préparer l\'installation.', 'violet', 'entite', [
            'clients.voir', 'kyc.voir', 'evaluations.voir', 'contrats.voir', 'credit_scoring.voir',
            'preparation_installation.voir', 'preparation_installation.creer', 'assignation_kits.voir', 'installations.voir',
            'messages.voir', 'messages.envoyer',
        ]],
        'shop_manager' => ['Shop manager', 'Assigne les kits aux clients de son shop prêts pour l\'installation.', 'orange', 'shop', [
            'tableau_de_bord.voir', 'clients.voir', 'kyc.voir', 'evaluations.voir', 'contrats.voir',
            'preparation_installation.voir', 'assignation_kits.voir', 'assignation_kits.modifier', 'installations.voir', 'installations.creer',
        ]],
        // Technicien : rattaché à un shop (par sa fiche agent), il ne voit que les clients de ce shop
        'technicien' => ['Technicien', 'Installe les kits chez les clients de son shop ; consulte leurs dossiers et les installations.', 'vert', 'shop', [
            'clients.voir', 'kyc.voir', 'evaluations.voir', 'contrats.voir',
            'assignation_kits.voir', 'installations.voir', 'installations.creer',
        ]],
    ];

    /** @var array<int, array{permissions: array<string, true>, admin: bool, perimetre: string}> mémoire de la requête */
    private static array $memoire = [];

    public static function installe(): bool
    {
        return Cache::remember('acces-installe', now()->addMinutes(10),
            fn () => Schema::hasTable('crm_roles') && Schema::hasTable('crm_role_permissions') && Schema::hasTable('crm_role_utilisateur'));
    }

    /** Toutes les permissions du catalogue. @return array<int, string> */
    public static function toutesLesPermissions(): array
    {
        return collect(self::MODULES)->flatMap(fn ($m, $module) => collect($m[2])->map(fn ($a) => "{$module}.{$a}"))->values()->all();
    }

    /** Permission d'un module ; une action non prévue pour le module retombe sur « modifier ». */
    public static function normaliser(string $permission): string
    {
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, 'voir');
        $actions = self::MODULES[$module][2] ?? [];

        return in_array($action, $actions, true) || $actions === [] ? $permission : "{$module}.modifier";
    }

    public static function peut(string $permission, ?User $utilisateur = null): bool
    {
        if (! self::installe()) {
            return true;
        }

        // Commandes artisan (personne de connecté) : pas de restriction. Un utilisateur connecté suit ses droits.
        $utilisateur ??= Auth::user();
        if (! $utilisateur) {
            return app()->runningInConsole();
        }

        $profil = self::profil($utilisateur);

        return $profil['admin'] || isset($profil['permissions'][self::normaliser($permission)]);
    }

    public static function estAdministrateur(?User $utilisateur = null): bool
    {
        $utilisateur ??= Auth::user();

        return ! self::installe() || ($utilisateur && self::profil($utilisateur)['admin']);
    }

    /** @return array{permissions: array<string, true>, admin: bool, perimetre: string} */
    public static function profil(User $utilisateur): array
    {
        // Gardé 5 min en cache (base distante : 2 requêtes de moins par page) ; toute modification
        // d'un rôle, d'une permission ou d'un utilisateur change la version et l'invalide aussitôt.
        $cle = 'acces-profil-'.$utilisateur->getKey().'-v'.Cache::get('acces-version', 1);

        return self::$memoire[$utilisateur->getKey()] ??= Cache::remember($cle, now()->addMinutes(5), fn () => (function () use ($utilisateur) {
            $roles = DB::table('crm_roles')
                ->join('crm_role_utilisateur', 'crm_role_utilisateur.role_id', '=', 'crm_roles.id')
                ->where('crm_role_utilisateur.user_id', $utilisateur->getKey())
                ->get(['crm_roles.id', 'crm_roles.code', 'crm_roles.perimetre']);

            $permissions = DB::table('crm_role_permissions')->whereIn('role_id', $roles->pluck('id'))->pluck('permission')
                ->mapWithKeys(fn ($p) => [$p => true])->all();

            $ordre = array_keys(self::PERIMETRES);
            $perimetre = $roles->pluck('perimetre')->sortBy(fn ($p) => array_search($p, $ordre, true))->first() ?? 'personnel';

            return [
                'permissions' => $permissions,
                'admin' => $roles->contains('code', 'administrateur') || isset($permissions['*']),
                'perimetre' => $perimetre,
            ];
        })());
    }

    public static function oublier(): void
    {
        self::$memoire = [];
        // Nouvelle version : les profils et autorisations de connexion en cache ne servent plus
        Cache::forever('acces-version', Cache::get('acces-version', 1) + 1);
    }

    /** L'utilisateur peut-il se connecter ? (compte actif, agent pas sorti, au moins un rôle) */
    public static function peutSeConnecter(User $utilisateur): bool
    {
        if (! self::installe()) {
            return true;
        }

        if ($utilisateur->actif === false) {
            return false;
        }

        // Vérifié à chaque page par Filament : mis en cache 5 min (même version que les profils)
        $cle = 'acces-connexion-'.$utilisateur->getKey().'-'.$utilisateur->id_agent.'-v'.Cache::get('acces-version', 1).'-'.now()->toDateString();

        return Cache::remember($cle, now()->addMinutes(5), function () use ($utilisateur) {
            if ($utilisateur->id_agent && ($sortie = DB::table('agent')->where('id_agent', $utilisateur->id_agent)->value('date_sortie_agent'))
                && now()->startOfDay()->gt($sortie)) {
                return false;
            }

            return DB::table('crm_role_utilisateur')->where('user_id', $utilisateur->getKey())->exists();
        });
    }

    /**
     * Limite une requête sur les clients au périmètre de l'utilisateur connecté.
     * Aucun filtre en ligne de commande, sans utilisateur connecté, ou tant que les accès ne sont pas installés.
     */
    public static function filtrerClients(Builder $query, string $colonneEntite, string $colonneAgent, ?string $colonneLocalite = null): void
    {
        // Sans utilisateur connecté (commandes artisan) : aucun filtre
        if (! self::installe() || ! ($utilisateur = Auth::user())) {
            return;
        }

        $profil = self::profil($utilisateur);
        if ($profil['admin']) {
            return;
        }

        $entites = array_filter((array) ($utilisateur->entites_autorisees ?? []));
        $agent = $utilisateur->id_agent;

        switch ($profil['perimetre']) {
            case 'personnel':
                $agent ? $query->where($colonneAgent, $agent) : $query->whereRaw('1 = 0');
                break;
            case 'equipe':
                $agent ? $query->whereIn($colonneAgent, self::equipe($agent)) : $query->whereRaw('1 = 0');
                break;
            case 'entite':
                $entites = $entites ?: array_filter([$agent ? DB::table('agent')->where('id_agent', $agent)->value('id_entite') : null]);
                $entites ? $query->whereIn($colonneEntite, $entites) : $query->whereRaw('1 = 0');

                return;
            case 'shop':
                // Localités rattachées au shop de l'agent du compte
                $localites = $agent && $colonneLocalite ? self::localitesDuShop($agent) : [];
                $localites ? $query->whereIn($colonneLocalite, $localites) : $query->whereRaw('1 = 0');
                break;
        }

        // « Toutes les données », « équipe » ou « personnel » : limité en plus aux entités cochées, s'il y en a
        if ($entites) {
            $query->whereIn($colonneEntite, $entites);
        }
    }

    /** Localités du shop de l'agent (vide si l'agent n'a pas de shop). @return array<int, int> */
    public static function localitesDuShop(int $agent): array
    {
        return Cache::remember("acces-shop-{$agent}", now()->addMinutes(10), function () use ($agent) {
            $shop = DB::table('agent')->where('id_agent', $agent)->value('id_site_shop');

            return $shop ? DB::table('localite_commune')->where('id_site_shop', $shop)->pluck('id_localite_commune')->map(fn ($id) => (int) $id)->all() : [];
        });
    }

    /** Ce client fait-il partie du périmètre de cet utilisateur ? (même règle que filtrerClients) */
    public static function peutVoirClient(User $utilisateur, \App\Models\Client $client): bool
    {
        if (! self::installe()) {
            return true;
        }

        $profil = self::profil($utilisateur);
        if ($profil['admin']) {
            return true;
        }

        $entites = array_map('intval', array_filter((array) ($utilisateur->entites_autorisees ?? [])));
        $agent = $utilisateur->id_agent;
        $entiteClient = (int) $client->entite_id_client;

        $dansEntite = fn (array $liste) => $liste === [] || in_array($entiteClient, $liste, true);

        return match ($profil['perimetre']) {
            'personnel' => $agent && (int) $client->id_agent === (int) $agent && $dansEntite($entites),
            'equipe' => $agent && in_array((int) $client->id_agent, self::equipe($agent), true) && $dansEntite($entites),
            'entite' => ($liste = $entites ?: array_filter([$agent ? (int) DB::table('agent')->where('id_agent', $agent)->value('id_entite') : null]))
                && in_array($entiteClient, $liste, true),
            default => $dansEntite($entites),
        };
    }

    /** L'agent et tous les agents qu'il supervise (directement ou non). @return array<int, int> */
    public static function equipe(int $agent): array
    {
        return Cache::remember("acces-equipe-{$agent}", now()->addMinutes(10), fn () => collect(DB::select(
            'WITH RECURSIVE equipe AS (
                SELECT id_agent FROM agent WHERE id_agent = ?
                UNION
                SELECT a.id_agent FROM agent a JOIN equipe e ON a.sup_agent_id = e.id_agent
            ) SELECT id_agent FROM equipe',
            [$agent],
        ))->pluck('id_agent')->map(fn ($id) => (int) $id)->all());
    }

    /** Pour les listes de rôles : [id => nom]. */
    public static function optionsRoles(): array
    {
        return self::installe() ? DB::table('crm_roles')->orderBy('nom')->pluck('nom', 'id')->all() : [];
    }

    public static function libellePermission(string $permission): string
    {
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, '');

        return (self::MODULES[$module][0] ?? $module).' · '.(self::ACTIONS[$action] ?? $action);
    }
}
