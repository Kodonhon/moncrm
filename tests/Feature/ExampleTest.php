<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerReferences();
    }

    public function test_la_racine_redirige_vers_la_liste_des_clients(): void
    {
        $this->get('/')->assertRedirect('/admin/clients');
        // L'accueil du panneau ouvre la liste des clients (homeUrl), comme la racine du site
        $this->get('/admin')->assertRedirect('/admin/clients');
        // Déjà connecté (connexion automatique) : la page de connexion renvoie vers le CRM
        $this->get('/admin/login')->assertRedirectContains('/admin');
    }

    public function test_les_pages_s_ouvrent_sans_connexion(): void
    {
        $this->creerClient();

        foreach (['/admin/clients', '/admin/clients/create', '/admin/tableau-de-bord', '/admin/parametres/formulaire-client',
            '/admin/parametres/canaux-de-vente', '/admin/parametres/localites', '/admin/parametres/villes'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->assertAuthenticated();
    }

    public function test_ajouter_un_client(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateClient::class)
            ->fillForm([
                'nom_client' => 'KOUASSI',
                'prenoms_client' => 'Aya',
                'genre_client' => 0,
                'contact1_client' => '07 07 07 07 07',
                'ville_filtre' => 13,
                'id_localite_commune' => 3,
                'id_canal_vente' => 1,
                'date_client' => '2026-10-19',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirectContains('/admin/clients/');

        $client = Client::where('nom_client', 'KOUASSI')->firstOrFail();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $client->id_client);
        $this->assertSame('2250707070707', $client->contact1_client);
        $this->assertSame(1, (int) $client->entite_id_client); // valeur par défaut : Greeno
        $this->assertSame(3, (int) $client->id_localite_commune); // localité précise choisie : gardée
    }

    public function test_le_journal_du_client_garde_chaque_changement(): void
    {
        \Illuminate\Support\Facades\Cache::forget('table-journal-client-disponible');
        $client = $this->creerClient(['nom_client' => 'KONE']); // sans utilisateur connecté (import) : pas journalisé
        $this->assertSame(0, \App\Models\JournalClient::count());

        $this->actingAs(User::factory()->create(['name' => 'Awa Terrain']));
        $client->update(['nom_client' => 'KONE ADAMA', 'contact1_client' => '2250707070799']);
        $client->kycs()->create(['id_kyc' => 'KJ1', 'num_piece_id' => 'CI0099']);

        $journal = \App\Models\JournalClient::where('id_client', $client->id_client)->orderBy('id')->get();
        $this->assertSame(['modifie', 'cree'], $journal->pluck('action')->all());
        $this->assertSame(['client', 'kyc'], $journal->pluck('objet')->all());
        $this->assertSame('Awa Terrain', $journal->first()->nom_utilisateur);
        $this->assertSame(['KONE', 'KONE ADAMA'], collect($journal->first()->changements)->first(fn ($v) => $v[1] === 'KONE ADAMA'));

        // Onglet « Journal » de la fiche
        $this->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk()
            ->assertSee('Journal')->assertSee('Awa Terrain')->assertSee('KONE ADAMA');
    }

    public function test_la_ville_suffit_et_la_localite_est_facultative(): void
    {
        $this->actingAs(User::factory()->create());

        // Sans ville : refusé
        Livewire::test(CreateClient::class)
            ->fillForm(['nom_client' => 'SANS VILLE', 'contact1_client' => '07 07 07 07 08', 'id_canal_vente' => 1, 'date_client' => '2026-10-21'])
            ->call('create')
            ->assertHasFormErrors(['ville_filtre' => 'required']);

        // Ville seule : rattaché à la localité du même nom (GAGNOA), donc à son shop
        Livewire::test(CreateClient::class)
            ->fillForm(['nom_client' => 'VILLE SEULE', 'contact1_client' => '07 07 07 07 09', 'id_canal_vente' => 1,
                'date_client' => '2026-10-21', 'ville_filtre' => 13])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, (int) Client::where('nom_client', 'VILLE SEULE')->value('id_localite_commune'));
    }

    public function test_la_source_est_deduite_du_canal(): void
    {
        $terrain = $this->creerClient(['id_canal_vente' => 1]);        // canal Direct → Terrain
        $marketing = $this->creerClient(['id_canal_vente' => 2]);      // canal Réseaux sociaux → Marketing
        $saisie = $this->creerClient(['id_canal_vente' => 1, 'id_src_client' => 2]);

        $this->assertSame('Terrain', $terrain->source_effective->lib_src_client);
        $this->assertSame('Marketing', $saisie->source_effective->lib_src_client);
        $this->assertEqualsCanonicalizing([$marketing->id_client, $saisie->id_client], Client::deSource(2)->pluck('id_client')->all());
    }

    public function test_les_vues_filtrent_la_liste(): void
    {
        $this->actingAs(User::factory()->create());
        $actif = $this->creerClient();
        $desactive = $this->creerClient(['visibilite_client' => 0]);
        $ancien = $this->creerClient(['date_client' => '2024-01-01']);
        $sansVisibilite = $this->creerClient(['visibilite_client' => null]); // cas réel : visibilité vide = actif

        $autreShop = $this->creerClient(['id_localite_commune' => 1]); // DALOA → SHOP DALOA (9)

        // Par défaut : clients actifs uniquement (visibilité vide comprise)
        Livewire::test(ListClients::class)
            ->assertCanSeeTableRecords([$actif, $ancien, $sansVisibilite])
            ->assertCanNotSeeTableRecords([$desactive]);

        Livewire::test(ListClients::class)
            ->filterTable('affichage', 'desactives')
            ->assertCanSeeTableRecords([$desactive])
            ->assertCanNotSeeTableRecords([$actif]);

        Livewire::test(ListClients::class)
            ->filterTable('periode', ['du' => now()->subDays(7)->toDateString(), 'au' => null])
            ->assertCanSeeTableRecords([$actif, $sansVisibilite])
            ->assertCanNotSeeTableRecords([$ancien]);

        Livewire::test(ListClients::class)
            ->filterTable('shop', 5)
            ->assertCanSeeTableRecords([$actif])
            ->assertCanNotSeeTableRecords([$autreShop]);
    }

    public function test_la_fiche_client_s_affiche_en_lecture(): void
    {
        $client = $this->creerClient([
            'nom_client' => 'KONE', 'prenoms_client' => 'Adama', 'profession_client' => 'Planteur',
            'systeme_souhaite_client' => 'TEVIA ACCES', 'horizon_achat_client' => 'D\'ici 1 semaine', 'id_type_piece_id' => 1,
        ]);

        $this->get(ClientResource::getUrl('view', ['record' => $client]))
            ->assertOk()
            ->assertSee('KONE Adama')
            ->assertSee('+225 01 02 03 04 05')
            ->assertSee('Gagnoa')            // localité
            ->assertSee('Shop Gagnoa')       // shop de la localité
            ->assertSee('Planteur')
            ->assertSee('CNI')               // libellé du type de pièce, pas son numéro
            ->assertSee('D&#039;ici 1 semaine', false)
            ->assertSee('WhatsApp')
            ->assertDontSee('name="data.nom_client"', false); // pas de champ de saisie

        // Un clic sur une ligne de la liste ouvre la fiche
        $this->actingAs(User::factory()->create());
        $this->assertSame(
            ClientResource::getUrl('view', ['record' => $client]),
            Livewire::test(ListClients::class)->instance()->getTable()->getRecordUrl($client),
        );
    }

    public function test_choix_des_colonnes_de_la_liste(): void
    {
        $this->actingAs(User::factory()->create());

        $table = Livewire::test(ListClients::class)->instance()->getTable();
        $masquables = collect($table->getColumns())->filter->isToggleable()->keys()->all();

        // Colonnes principales affichées par défaut ; six colonnes secondaires masquées au départ
        // pour que le tableau tienne à l'écran. Toutes se réaffichent avec « Colonnes », sauf « Client » toujours visible.
        foreach (['id_client', 'contact1_client', 'piece', 'statut_kyc', 'statut_eval', 'id_localite_commune', 'ville', 'id_canal_vente', 'id_agent', 'date_client'] as $colonne) {
            $this->assertContains($colonne, $masquables);
            $this->assertFalse($table->getColumn($colonne)->isToggledHiddenByDefault());
        }
        foreach (['contact2_client', 'indication_site_client', 'entite_id_client', 'genre_client', 'source', 'shop'] as $colonne) {
            $this->assertContains($colonne, $masquables);
            $this->assertTrue($table->getColumn($colonne)->isToggledHiddenByDefault());
        }
        $this->assertNotContains('nom_client', $masquables);
    }

    public function test_plus_de_panneau_lateral_sur_la_liste(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertSame([], Livewire::test(ListClients::class)->instance()->getSubNavigation());
    }
}
