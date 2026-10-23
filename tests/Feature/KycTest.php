<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Kyc;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

class KycTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerReferences();
        $this->actingAs(User::factory()->create());
        Storage::fake('public');
    }

    private function actionKyc(string $nom): TestAction
    {
        return TestAction::make($nom)->schemaComponent('section-kyc', schema: 'infolist');
    }

    public function test_fiche_sans_kyc_propose_d_en_ajouter_un(): void
    {
        $client = $this->creerClient();

        $this->get(ClientResource::getUrl('view', ['record' => $client]))
            ->assertOk()
            ->assertSee('KYC à faire')
            ->assertSee('Ajouter le KYC')
            ->assertDontSee('Modifier le KYC');
    }

    public function test_ajouter_un_kyc_depuis_la_fiche(): void
    {
        $client = $this->creerClient(['id_type_piece_id' => 1]);

        Livewire::test(ViewClient::class, ['record' => $client->getKey()])
            ->assertActionVisible($this->actionKyc('ajouterKyc'))
            ->mountAction($this->actionKyc('ajouterKyc'))
            ->assertSchemaStateSet(['type_piece_id' => 1], 'mountedActionSchema0') // repris du client
            ->setActionData([
                'num_piece_id' => 'CI001156545',
                'd_nais' => '1981-08-22',
                'nom_parent_proche' => 'AYA SANDRINE',
                'id_lien_par' => 8,
                'contact_parent_1' => '0545387871',
                'id_type_vente' => 1,
                'id_agent' => 1,
                'photo_id_face1' => UploadedFile::fake()->image('recto.jpg'),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $kyc = $client->fresh()->kyc;
        $this->assertNotNull($kyc);
        $this->assertSame('CI001156545', $kyc->num_piece_id);
        $this->assertSame('2250545387871', $kyc->contact_parent_1);
        $this->assertSame('Épouse', $kyc->lien_parente); // texte d'origine tenu à jour depuis la liste
        $this->assertNotNull($kyc->photo_id_face1);
        Storage::disk('public')->assertExists($kyc->photo_id_face1);

        $this->get(ClientResource::getUrl('view', ['record' => $client]))
            ->assertSee('KYC fait')
            ->assertSee('Modifier le KYC')
            ->assertSee('CI001156545')
            ->assertSee('Épouse')
            ->assertSee('Payg')
            ->assertSee('Koffi Yao');
    }

    public function test_le_client_doit_avoir_entre_18_et_76_ans(): void
    {
        $client = $this->creerClient();
        $kyc = fn (string $naissance) => [
            'type_piece_id' => 1, 'num_piece_id' => 'CI0001', 'd_nais' => $naissance, 'nom_parent_proche' => 'AYA', 'id_lien_par' => 8, 'contact_parent_1' => '0545387871',
        ];
        $fiche = fn () => Livewire::test(ViewClient::class, ['record' => $client->getKey()]);

        // 17 ans (18 ans demain) : refusé
        $fiche()->callAction($this->actionKyc('ajouterKyc'), data: $kyc(now()->subYears(18)->addDay()->toDateString()))
            ->assertHasActionErrors(['d_nais' => 'before_or_equal']);
        // 77 ans révolus aujourd'hui : refusé
        $fiche()->callAction($this->actionKyc('ajouterKyc'), data: $kyc(now()->subYears(77)->toDateString()))
            ->assertHasActionErrors(['d_nais' => 'after_or_equal']);
        $this->assertSame(0, Kyc::count());

        // 18 ans aujourd'hui, et 76 ans (77 ans demain) : acceptés
        $fiche()->callAction($this->actionKyc('ajouterKyc'), data: $kyc(now()->subYears(18)->toDateString()))->assertHasNoActionErrors();
        $this->assertSame(18, $client->fresh()->kyc->age());

        $client->kyc->update(['d_nais' => now()->subYears(77)->addDay()->toDateString()]);
        $this->assertSame(76, $client->fresh()->kyc->age());
        $this->assertTrue($client->fresh()->kyc->ageValide());

        // Donnée ancienne hors limites : signalée sur la fiche
        $client->kyc->update(['d_nais' => '1940-05-01']);
        $this->get(ClientResource::getUrl('view', ['record' => $client]))->assertSee('hors limites (18–76 ans)');
    }

    public function test_les_champs_obligatoires_du_kyc_sont_controles(): void
    {
        $client = $this->creerClient();

        Livewire::test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction($this->actionKyc('ajouterKyc'), data: ['num_piece_id' => null])
            ->assertHasActionErrors(['num_piece_id' => 'required', 'nom_parent_proche' => 'required', 'id_lien_par' => 'required']);

        $this->assertSame(0, Kyc::count());
    }

    public function test_modifier_un_kyc_existant(): void
    {
        $client = $this->creerClient();
        // KYC importé : lien saisi en texte libre, photo dont le fichier d'origine n'existe pas
        $client->kycs()->create([
            'id_kyc' => '1000', 'type_piece_id' => 1, 'num_piece_id' => 'C0079436928', 'd_nais' => '1972-01-01',
            'nom_parent_proche' => 'Yao kouadio jean', 'lien_parente' => 'FRERE', 'contact_parent_1' => '2250504521530',
            'photo_id_face1' => 'PHOTO TDH/C0079436928-Akpo/recto.jpg', 'visibilite_kyc' => 1,
        ]);

        $this->get(ClientResource::getUrl('view', ['record' => $client]))
            ->assertSee('Photo d&#039;origine non disponible', false);

        Livewire::test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction($this->actionKyc('modifierKyc'), data: ['id_lien_par' => 5, 'num_piece_id' => 'C0079436929'])
            ->assertHasNoActionErrors();

        $kyc = Kyc::find('1000');
        $this->assertSame('C0079436929', $kyc->num_piece_id);
        $this->assertSame('Frère', $kyc->lien_parente);
        $this->assertSame('1972-01-01', $kyc->d_nais->toDateString());
    }

    public function test_un_kyc_vide_d_origine_ne_compte_pas_comme_fait(): void
    {
        $avec = $this->creerClient();
        $avec->kycs()->create(['id_kyc' => 'A1', 'num_piece_id' => 'X', 'visibilite_kyc' => 1]);
        $coquille = $this->creerClient();
        $coquille->kycs()->create(['id_kyc' => 'A2', 'visibilite_kyc' => 0]);

        $this->assertNull($coquille->fresh()->kyc);

        Livewire::test(ListClients::class)
            ->filterTable('kyc', 'fait')
            ->assertCanSeeTableRecords([$avec])
            ->assertCanNotSeeTableRecords([$coquille]);

        Livewire::test(ListClients::class)
            ->filterTable('kyc', 'a_faire')
            ->assertCanSeeTableRecords([$coquille])
            ->assertCanNotSeeTableRecords([$avec]);
    }

    public function test_indicateur_de_validite_de_la_piece(): void
    {
        $valide = $this->creerClient();
        $valide->kycs()->create(['id_kyc' => 'V', 'num_piece_id' => 'A', 'date_expir_doc' => now()->addYears(3)->toDateString()]);
        $bientot = $this->creerClient();
        $bientot->kycs()->create(['id_kyc' => 'B', 'num_piece_id' => 'B', 'date_expir_doc' => now()->addDays(12)->toDateString()]);
        $expiree = $this->creerClient();
        $expiree->kycs()->create(['id_kyc' => 'E', 'num_piece_id' => 'C', 'date_expir_doc' => '2025-11-13']);
        $inconnue = $this->creerClient();
        $inconnue->kycs()->create(['id_kyc' => 'I', 'num_piece_id' => 'D']);

        $this->assertSame('valide', $valide->fresh()->kyc->etatPiece()['etat']);
        $this->assertSame('Pièce expire dans 12 jours', $bientot->fresh()->kyc->etatPiece()['libelle']);
        $this->assertSame('Pièce expirée depuis le 13/11/2025', $expiree->fresh()->kyc->etatPiece()['libelle']);
        $this->assertSame('inconnue', $inconnue->fresh()->kyc->etatPiece()['etat']);

        $this->get(ClientResource::getUrl('view', ['record' => $expiree]))->assertSee('Pièce expirée depuis le 13/11/2025');
        $this->get(ClientResource::getUrl('view', ['record' => $valide]))->assertSee('Pièce valide jusqu&#039;au', false);

        Livewire::test(ListClients::class)
            ->filterTable('kyc', 'expiree')
            ->assertCanSeeTableRecords([$expiree])
            ->assertCanNotSeeTableRecords([$valide, $bientot, $inconnue]);

        Livewire::test(ListClients::class)
            ->filterTable('kyc', 'bientot')
            ->assertCanSeeTableRecords([$bientot])
            ->assertCanNotSeeTableRecords([$valide, $expiree]);
    }

    public function test_l_ecran_parametres_du_formulaire_kyc(): void
    {
        $this->get('/admin/parametres/formulaire-kyc')->assertOk()->assertSee('Numéro de la pièce')->assertDontSee('Indication du site');
        $this->get('/admin/parametres/formulaire-client')->assertOk()->assertSee('Indication du site')->assertDontSee('Numéro de la pièce');
        $this->get('/admin/parametres/liens-de-parente')->assertOk()->assertSee('Épouse');
        $this->get('/admin/parametres/types-de-vente')->assertOk()->assertSee('Payg');
    }
}
