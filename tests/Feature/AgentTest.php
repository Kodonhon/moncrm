<?php

namespace Tests\Feature;

use App\Filament\Clusters\Parametres\Resources\Pages\GererAgents;
use App\Models\Agent;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

/** Paramètres > Gestion des agents : liste, ajout, modification, désactivation, suppression ; import du fichier RH. */
class AgentTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('table-credit-scoring-disponible');
        $this->creerReferences();
        $this->actingAs(User::factory()->create());
    }

    private function page()
    {
        return Livewire::test(GererAgents::class)->set('activeTab', 'tous');
    }

    public function test_ajouter_modifier_et_desactiver_un_agent(): void
    {
        $this->page()
            ->callAction('create', data: [
                'nom_agent' => 'TRAORE', 'prenoms_agent' => 'Awa', 'contact_agent' => '0707070707',
                'id_entite' => 1, 'id_site_shop' => 5, 'role_agent' => 'credit', 'visibilite_agent' => true,
            ])
            ->assertHasNoActionErrors();

        $agent = Agent::where('nom_agent', 'TRAORE')->firstOrFail();
        $this->assertSame('TRAORE Awa', $agent->nom_complet_agent);
        $this->assertSame('credit', $agent->role_agent);

        $this->page()
            ->assertCanSeeTableRecords([$agent])
            ->assertSee('Agent crédit')
            ->callAction(TestAction::make('edit')->table($agent), data: ['prenoms_agent' => 'Awa Mariam', 'role_agent' => 'terrain'])
            ->assertHasNoActionErrors();
        $this->assertSame('TRAORE Awa Mariam', $agent->fresh()->nom_complet_agent);

        // Le nom est obligatoire
        $this->page()->callAction('create', data: ['nom_agent' => null])->assertHasActionErrors(['nom_agent' => 'required']);

        // Désactivé : il n'est plus proposé dans les listes de choix
        $agent->update(['visibilite_agent' => 0]);
        $this->assertArrayNotHasKey($agent->id_agent, Agent::options());
    }

    public function test_suppression_seulement_si_l_agent_n_est_pas_utilise(): void
    {
        $libre = Agent::create(['nom_agent' => 'LIBRE', 'visibilite_agent' => 1]);
        $avecClient = Agent::create(['nom_agent' => 'OCCUPE', 'visibilite_agent' => 1]);
        $this->creerClient(['id_agent' => $avecClient->id_agent]);
        $cite = Agent::create(['nom_agent' => 'CITE', 'visibilite_agent' => 1]);
        DB::table('credit_scoring')->insert(['id_cs' => 'CS1', 'id_agent' => $cite->id_agent]);

        $this->page()
            ->assertActionHidden(TestAction::make('delete')->table($avecClient))
            ->callAction(TestAction::make('delete')->table($libre))
            ->callAction(TestAction::make('delete')->table($cite))
            ->assertNotified('Cet agent ne peut pas être supprimé');

        $this->assertNull(Agent::find($libre->id_agent));
        $this->assertNotNull(Agent::find($cite->id_agent));
    }

    public function test_import_du_fichier_rh(): void
    {
        // Agent provisoire créé par l'import du credit scoring : il reçoit son vrai nom et garde son rôle
        DB::table('agent')->insert(['id_agent' => 793, 'nom_agent' => 'Agent crédit n° 793 (historique)', 'visibilite_agent' => 1, 'role_agent' => 'credit']);

        $fichier = tempnam(sys_get_temp_dir(), 'agents').'.csv';
        file_put_contents($fichier, implode("\n", [
            '"id_agent","id_fonction_agent","id_type_agent","id_zone_agent","id_dpt","id_int","id_entite","sup_agent_id","nom_agent","prenoms_agent","genre_agent","date_entree_agent","date_elearning_agent","date_sortie_agent","num_phone_agent","photo_agent","email_agent","visibilite_agent"',
            '780,1,1,1,6,1,1,0,"KOUASSI","Jean",1,"2024-01-02",NULL,NULL,"0707000000",NULL,NULL,1',
            '793,3,2,4,6,1,1,780,"Goue","Mylene Saty",0,NULL,NULL,NULL,NULL,NULL,"mylene.goue@greeno.solar",1',
        ]));

        $this->artisan('crm:importer-agents', ['fichier' => $fichier])->assertSuccessful();

        $mylene = Agent::find(793);
        $this->assertSame('Goue Mylene Saty', $mylene->nom_complet_agent);
        $this->assertSame('credit', $mylene->role_agent);
        $this->assertSame(780, (int) $mylene->sup_agent_id);
        $this->assertSame('0707000000', Agent::find(780)->contact_agent);
        $this->assertNull(Agent::find(780)->sup_agent_id); // 0 = aucun supérieur
    }
}
