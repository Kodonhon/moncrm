<?php

namespace Tests\Feature;

use App\Filament\Clusters\Parametres\Resources\FormulaireEvalSiteResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererFormulaireEvalSite;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\EvalSitesRelationManager;
use App\Formulaires\ConstructeurFormulaire;
use App\Formulaires\Formule;
use App\Models\Client;
use App\Models\EvalSite;
use App\Models\FormulaireChamp;
use App\Models\FormulaireSection;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

class EvalSiteTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerReferences();
        $this->actingAs(User::factory()->create());
        Storage::fake('public');
    }

    private function produits(Client $client)
    {
        return Livewire::test(EvalSitesRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
    }

    private function ajouter(): TestAction
    {
        return TestAction::make('ajouterEvalSite')->table();
    }

    private function clientAvecKyc(array $kyc = []): Client
    {
        $client = $this->creerClient();
        $client->kycs()->create($kyc + ['id_kyc' => 'K'.$client->id_client, 'num_piece_id' => 'CI001', 'id_agent' => 1]);

        return $client->fresh();
    }

    public function test_l_evaluation_est_independante_du_kyc(): void
    {
        $sansKyc = $this->creerClient();

        $this->get(ClientResource::getUrl('view', ['record' => $sansKyc]))->assertSee('KYC à faire'); // alerte prioritaire
        $this->produits($sansKyc)->assertSee('Aucun dimensionnement pour l\'instant')->assertSee('Faire un dimensionnement');

        $this->produits($sansKyc)
            ->callAction($this->ajouter(), data: ['id_type_eval_site' => 2])
            ->assertHasNoActionErrors();

        $this->assertSame(1, $sansKyc->evalSites()->count());
        $this->assertNull($sansKyc->fresh()->kyc);
        $this->assertNull($sansKyc->evalSites()->first()->id_kyc);
    }

    public function test_un_client_peut_avoir_plusieurs_produits_chacun_avec_son_tdh(): void
    {
        $client = $this->clientAvecKyc(['date_expir_doc' => now()->addYears(2)->toDateString()]);

        // 1er produit : pompage, TDH calculé par la formule par défaut
        $this->produits($client)
            ->callAction($this->ajouter(), data: [
                'id_type_eval_site' => 1, 'id_prod' => 1,
                'prof_reglage_pomp_tdh' => '20', 'haut_elevation_verticale_tdh' => '5', 'haut_reservoir_tdh' => '2',
                'haut_supp_reservoir_tdh' => '3', 'reservoir_ou_pas_tdh' => true,
                'dist_src_eau_et_reservoir_tdh' => '50', 'nb_coude_connecteur' => '4',
            ])
            ->assertHasNoActionErrors();

        // 2e produit : autre pompage, autres mesures → autre TDH
        $this->produits($client)
            ->callAction($this->ajouter(), data: ['id_type_eval_site' => 1, 'prof_reglage_pomp_tdh' => '10'])
            ->assertHasNoActionErrors();

        $evaluations = $client->evalSites()->orderBy('tdh', 'desc')->get();
        $this->assertCount(2, $evaluations);
        // 20 + 5 + 2 + 3 + 0,1 × 50 + 0,5 × 4 = 37
        $this->assertSame('37', $evaluations[0]->tdh);
        $this->assertSame('10', $evaluations[1]->tdh);

        // Le même KYC (pièce valide) est rattaché aux deux produits
        $this->assertSame(['K'.$client->id_client], $evaluations->pluck('id_kyc')->unique()->values()->all());

        $this->get(ClientResource::getUrl('view', ['record' => $client]))->assertSee('2 dimensionnements');
        $this->produits($client)
            ->assertCanSeeTableRecords($evaluations)
            ->assertSee('Pompe TEVIA 1 CV')
            ->assertSee('37,00 m');
    }

    public function test_le_tdh_se_recalcule_dans_le_formulaire(): void
    {
        $composant = $this->produits($this->clientAvecKyc())
            ->mountAction($this->ajouter())
            ->setActionData(['id_type_eval_site' => 1, 'prof_reglage_pomp_tdh' => '12', 'haut_elevation_verticale_tdh' => '3']);

        $this->assertEquals('15', $composant->instance()->getSchema('mountedActionSchema0')->getState()['tdh'] ?? null);
    }

    public function test_message_kyc_pour_un_nouveau_produit(): void
    {
        $valide = $this->clientAvecKyc(['date_expir_doc' => now()->addYear()->toDateString()]);
        $expire = $this->clientAvecKyc(['date_expir_doc' => '2025-01-01']);

        $this->assertStringContainsString('sera utilisé', (string) EvalSitesRelationManager::messageKyc($valide));
        $this->assertStringContainsString('mettez à jour le KYC', (string) EvalSitesRelationManager::messageKyc($expire));
        $this->assertStringContainsString('pas encore de KYC', (string) EvalSitesRelationManager::messageKyc($this->creerClient()));
    }

    public function test_la_section_tdh_n_apparait_que_pour_le_pompage(): void
    {
        $client = $this->clientAvecKyc();

        $this->produits($client)
            ->callAction($this->ajouter(), data: ['id_type_eval_site' => 2, 'besoin_jour_eau_tdh' => '12'])
            ->assertHasNoActionErrors();

        // Évaluation SHS : les réponses TDH ne sont ni demandées ni enregistrées
        $this->assertNull($client->evalSites()->first()->besoin_jour_eau_tdh);
    }

    public function test_le_type_d_evaluation_depend_de_l_entite_du_client(): void
    {
        $options = fn ($composant) => collect($composant->instance()->getSchema('mountedActionSchema0')->getFlatFields())->get('id_type_eval_site')->getOptions();

        $greeno = $this->creerClient(['entite_id_client' => 1]);
        $composant = $this->produits($greeno)->mountAction($this->ajouter())
            ->assertSchemaStateSet(['entite_client' => 1, 'id_type_eval_site' => 1], 'mountedActionSchema0');
        $this->assertSame([1 => 'Pompage solaire (TDH)'], $options($composant));

        $tevia = $this->creerClient(['entite_id_client' => 2]);
        $composant = $this->produits($tevia)->mountAction($this->ajouter())
            ->assertSchemaStateSet(['entite_client' => 2, 'id_type_eval_site' => 2], 'mountedActionSchema0');
        $this->assertSame([2 => 'Kit solaire domestique (SHS)'], $options($composant));

        $tous = $this->creerClient(['entite_id_client' => 0]);
        $this->assertCount(2, $options($this->produits($tous)->mountAction($this->ajouter())));

        $inconnue = $this->creerClient(['entite_id_client' => 3]);
        $this->assertCount(2, $options($this->produits($inconnue)->mountAction($this->ajouter())));

        // L'entité reprise du client fait foi, même si l'écran a été modifié
        $this->produits($greeno)->callAction($this->ajouter(), data: ['entite_client' => 2])->assertHasNoActionErrors();
        $this->assertSame(1, (int) $greeno->evalSites()->first()->id_type_eval_site);
    }

    public function test_valider_puis_filtrer_les_evaluations(): void
    {
        $aValider = $this->clientAvecKyc();
        $evaluation = $aValider->evalSites()->create(['id_type_eval_site' => 2]);
        $validee = $this->clientAvecKyc();
        $validee->evalSites()->create(['id_type_eval_site' => 1, 'statut_eval_site' => EvalSite::STATUT_VALIDEE]);
        $aFaire = $this->clientAvecKyc();

        $this->get(ClientResource::getUrl('view', ['record' => $aValider]))->assertSee('Valider 1 dimensionnement');

        Livewire::test(ListClients::class)->filterTable('eval_site', 'a_valider')
            ->assertCanSeeTableRecords([$aValider])->assertCanNotSeeTableRecords([$validee, $aFaire]);
        Livewire::test(ListClients::class)->filterTable('eval_site', 'a_faire')
            ->assertCanSeeTableRecords([$aFaire])->assertCanNotSeeTableRecords([$aValider, $validee]);

        // Fiche ouverte pendant la validation, faite dans le bloc « Dimensionnements » en dessous
        $fiche = Livewire::test(ViewClient::class, ['record' => $aValider->getKey()])->assertSee('0 / 1 validé');

        $this->produits($aValider)
            ->callAction(TestAction::make('validerEvalSite')->table($evaluation))
            ->assertHasNoActionErrors()
            ->assertDispatched(ViewClient::EVENEMENT_MODIFIEE); // le haut de la fiche se met à jour
        $this->assertTrue($evaluation->fresh()->estValidee());

        // À réception de l'événement, la fiche relit le client : le parcours suit
        $fiche->dispatch(ViewClient::EVENEMENT_MODIFIEE)
            ->assertSee('1 / 1 validé')
            ->assertDontSee('Valider 1 dimensionnement');
    }

    public function test_les_sections_sont_parametrables(): void
    {
        $sections = FormulaireSection::where('formulaire', 'eval_site')->orderBy('ordre')->get();
        $this->assertSame(['Général', 'Pompage solaire (TDH)', 'Kit solaire maison (SHS)', 'Sécurité (SST)'], $sections->pluck('nom')->all());

        $donnees = $sections->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom, 'description' => $s->description, 'visible' => true, 'conditions' => $s->conditions ?? []])->all();
        $securite = array_pop($donnees);
        $securite['nom'] = 'Sécurité';
        $donnees[2]['visible'] = false; // SHS
        array_unshift($donnees, $securite);

        Livewire::test(GererFormulaireEvalSite::class)
            ->callAction('sections', data: ['sections' => $donnees])
            ->assertHasNoActionErrors();

        $this->assertSame('Sécurité', FormulaireChamp::where('formulaire', 'eval_site')->where('cle', 'risk_vol_sst')->value('section'));
        $this->assertSame(['Sécurité', 'Général', 'Pompage solaire (TDH)'], array_map(fn ($s) => $s->getHeading(), (new ConstructeurFormulaire('eval_site'))->sections()));
    }

    public function test_formules_de_calcul(): void
    {
        $valeurs = ['a' => '2', 'b' => '3,5', 'c' => null];
        $v = fn ($cle) => $valeurs[$cle] ?? null;

        $this->assertSame(9.0, Formule::evaluer('{a} + {b} * 2', $v));
        $this->assertSame(11.0, Formule::evaluer('({a} + {b}) * 2', $v));
        $this->assertSame(2.0, Formule::evaluer('{a} + {c}', $v)); // vide = 0
        $this->assertSame(3.5, Formule::evaluer('max({a}; {b})', $v));
        $this->assertSame(0.7, Formule::evaluer('arrondi({a} / 3 + 0,03; 1)', $v));
        $this->assertSame(['a', 'b'], Formule::variables('{a} + {b} - {a}'));
        $this->assertNotNull(Formule::erreur('{a} + '));
        $this->assertNotNull(Formule::erreur('system("x")'));
        $this->assertNull(Formule::erreur('{a} * 1,5'));
    }

    public function test_formule_parametrable_et_controlee(): void
    {
        $tdh = FormulaireChamp::where('formulaire', 'eval_site')->where('cle', 'tdh')->firstOrFail();
        $this->assertSame('calcul', $tdh->type);

        // Formule incorrecte refusée
        Livewire::test(GererFormulaireEvalSite::class)
            ->callAction(TestAction::make('edit')->table($tdh), data: ['formule' => '{prof_reglage_pomp_tdh} + {inconnue}'])
            ->assertHasActionErrors(['formule']);

        // Nouvelle formule : prise en compte au prochain enregistrement
        Livewire::test(GererFormulaireEvalSite::class)
            ->callAction(TestAction::make('edit')->table($tdh), data: ['formule' => '{prof_reglage_pomp_tdh} * 2'])
            ->assertHasNoActionErrors();

        $client = $this->clientAvecKyc();
        $this->produits($client)->callAction($this->ajouter(), data: ['id_type_eval_site' => 1, 'prof_reglage_pomp_tdh' => '8'])->assertHasNoActionErrors();
        $this->assertSame('16', $client->evalSites()->first()->tdh);

        $this->get('/admin/parametres/'.FormulaireEvalSiteResource::getSlug())->assertOk()->assertSee('= {prof_reglage_pomp_tdh} * 2');
    }

    public function test_ecrans_de_parametres_de_l_evaluation(): void
    {
        $this->assertTrue(FormulaireChamp::where('formulaire', 'eval_site')->where('cle', 'etat_route_eval_site')->value('type_modifiable'));
        $this->assertFalse(FormulaireChamp::where('formulaire', 'eval_site')->where('cle', 'risk_vol_sst')->value('type_modifiable'));

        $this->get('/admin/parametres/formulaire-evaluation-site')->assertOk()->assertSee('Besoin en eau par jour');
        $this->get('/admin/parametres/produits')->assertOk()->assertSee('Pompe TEVIA 1 CV');
        $this->get('/admin/parametres/types-d-evaluation')->assertOk()->assertSee('Pompage solaire');
    }
}
