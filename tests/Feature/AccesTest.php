<?php

namespace Tests\Feature;

use App\Filament\Pages\AssignationKits;
use App\Filament\Pages\TableauDeBord;
use App\Filament\Pages\ValidationCredit;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Installations\InstallationResource;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Support\Acces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

/** Rôles et accès : permissions par module et périmètre des données (tout, entité, shop, équipe, ses clients). */
class AccesTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['acces-installe', 'table-credit-scoring-disponible', 'table-prep-inst-disponible', 'table-installation-disponible'] as $cle) {
            Cache::forget($cle);
        }
        $this->creerReferences();
        $this->artisan('crm:installer-acces', ['email' => 'admin@greeno.test', '--mot-de-passe' => 'MotDePasse-Test-2026'])->assertSuccessful();
    }

    /** Utilisateur rattaché à un agent, avec un rôle par défaut. */
    private function utilisateur(string $role, ?int $agent = null): User
    {
        $utilisateur = User::factory()->create(['id_agent' => $agent]);
        $utilisateur->roles()->attach(Role::where('code', $role)->value('id'), ['created_at' => now()]);
        Acces::oublier();

        return $utilisateur;
    }

    public function test_l_installation_cree_les_roles_par_defaut(): void
    {
        $this->assertEqualsCanonicalizing(
            ['administrateur', 'direction', 'responsable_commercial', 'commercial_terrain', 'agent_credit', 'agent_cx', 'shop_manager', 'technicien'],
            Role::pluck('code')->all(),
        );

        $shopManager = Role::where('code', 'shop_manager')->first();
        $this->assertSame('shop', $shopManager->perimetre);
        $this->assertContains('assignation_kits.modifier', $shopManager->listePermissions());

        $this->assertTrue(User::where('email', 'admin@greeno.test')->first()->roles()->where('code', 'administrateur')->exists());
    }

    public function test_gerer_les_permissions_module_par_module(): void
    {
        $this->actingAs(User::where('email', 'admin@greeno.test')->first());
        $direction = Role::where('code', 'direction')->first();

        $page = \Livewire\Livewire::test(\App\Filament\Clusters\Parametres\Pages\PermissionsRoles::class, ['role' => $direction->id])
            ->set('role', $direction->id)
            ->call('choisirModule', 'clients')
            ->assertSee('Module Clients');

        // Un interrupteur : activé puis désactivé
        $page->call('basculer', 'creer');
        $this->assertContains('clients.creer', $direction->fresh()->listePermissions());
        $page->call('basculer', 'creer');
        $this->assertNotContains('clients.creer', $direction->fresh()->listePermissions());

        // Tout activer / tout désactiver ne touche que le module affiché
        $page->call('toutBasculer', true);
        $this->assertEmpty(array_diff(['clients.voir', 'clients.creer', 'clients.modifier', 'clients.exporter', 'clients.supprimer'], $direction->fresh()->listePermissions()));
        $page->call('toutBasculer', false);
        $this->assertEmpty(array_filter($direction->fresh()->listePermissions(), fn ($p) => str_starts_with($p, 'clients.')));
        $this->assertContains('kyc.voir', $direction->fresh()->listePermissions());

        // Le rôle Administrateur ne se modifie pas
        $admin = Role::where('code', 'administrateur')->first();
        \Livewire\Livewire::test(\App\Filament\Clusters\Parametres\Pages\PermissionsRoles::class)
            ->set('role', $admin->id)
            ->call('toutBasculer', false);
        $this->assertTrue(Acces::peut('clients.voir'));
    }

    public function test_chaque_ecran_suit_sa_permission(): void
    {
        $this->actingAs($this->utilisateur('shop_manager', 1));
        $this->assertTrue(AssignationKits::canAccess());
        $this->assertTrue(InstallationResource::canViewAny());
        $this->assertTrue(ClientResource::canViewAny());
        $this->assertFalse(ValidationCredit::canAccess());       // pas de credit scoring
        $this->assertFalse(ClientResource::canCreate());         // ne crée pas de clients
        $this->get(ValidationCredit::getUrl())->assertForbidden();

        $this->actingAs($this->utilisateur('agent_credit', 1));
        $this->assertTrue(ValidationCredit::canAccess());
        $this->assertFalse(AssignationKits::canAccess());
        $this->assertFalse(TableauDeBord::canAccess());
    }

    public function test_perimetre_ses_clients_et_son_shop(): void
    {
        // Agent 1 : shop 5 (GAGNOA) ; agent 3 : shop 9 (DALOA)
        DB::table('agent')->insert(['id_agent' => 3, 'nom_agent' => 'Awa Daloa', 'id_site_shop' => 9, 'visibilite_agent' => 1]);
        $gagnoa = $this->creerClient(['id_localite_commune' => 2, 'id_agent' => 1]);   // GAGNOA, shop 5
        $guiberoua = $this->creerClient(['id_localite_commune' => 3, 'id_agent' => 3]); // GUIBEROUA, shop 5, autre agent
        $daloa = $this->creerClient(['id_localite_commune' => 1, 'id_agent' => 3]);     // DALOA, shop 9

        // Commercial terrain (agent 1) : uniquement ses clients
        $this->actingAs($this->utilisateur('commercial_terrain', 1));
        $this->assertEqualsCanonicalizing([$gagnoa->id_client], Client::pluck('id_client')->all());

        // Shop manager de GAGNOA (agent 1, shop 5) : tous les clients des localités de son shop
        $this->actingAs($this->utilisateur('shop_manager', 1));
        $this->assertEqualsCanonicalizing([$gagnoa->id_client, $guiberoua->id_client], Client::pluck('id_client')->all());
        $this->get(ClientResource::getUrl('view', ['record' => $daloa]))->assertNotFound(); // client hors de son shop

        // Administrateur : tout
        $this->actingAs(User::where('email', 'admin@greeno.test')->first());
        $this->assertCount(3, Client::pluck('id_client'));
    }

    public function test_le_technicien_ne_voit_que_les_dossiers_de_son_shop(): void
    {
        foreach (['table-credit-scoring-disponible', 'table-installation-disponible'] as $cle) {
            Cache::forget($cle);
        }
        DB::table('agent')->insert(['id_agent' => 3, 'nom_agent' => 'Awa Daloa', 'id_site_shop' => 9, 'visibilite_agent' => 1]);
        $gagnoa = $this->creerClient(['id_localite_commune' => 2]); // shop 5
        $daloa = $this->creerClient(['id_localite_commune' => 1]);  // shop 9
        foreach ([$gagnoa, $daloa] as $client) {
            \App\Models\CreditScoring::create(['id_client' => $client->id_client, \App\Models\CreditScoring::CHAMP_DECISION => '1']);
            DB::table('installation')->insert(['id_installation' => 'I'.$client->id_client, 'id_client' => $client->id_client, 'systeme_installe' => 1]);
        }

        // Technicien rattaché (par sa fiche agent) au shop de GAGNOA : scorings, installations et clients de ce shop seulement
        $this->assertSame('shop', Role::where('code', 'technicien')->value('perimetre'));
        $this->actingAs($this->utilisateur('technicien', 1));
        $this->assertSame([$gagnoa->id_client], \App\Models\CreditScoring::pluck('id_client')->all());
        $this->assertSame([$gagnoa->id_client], \App\Models\Installation::pluck('id_client')->all());
        $this->assertTrue(Acces::peut('installations.voir'));
        $this->assertFalse(Acces::peut('clients.modifier'));

        // Administrateur : tout
        $this->actingAs(User::where('email', 'admin@greeno.test')->first());
        $this->assertSame(2, \App\Models\Installation::count());
    }

    public function test_un_compte_sans_agent_ne_voit_aucun_client_de_shop(): void
    {
        $this->creerClient();
        $this->actingAs($this->utilisateur('shop_manager'));

        $this->assertSame(0, Client::count());
    }
}
