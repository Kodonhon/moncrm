<?php

namespace Tests\Feature;

use App\Filament\Pages\ValidationCredit;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\CreditScoringsRelationManager;
use App\Models\Agent;
use App\Models\Client;
use App\Models\CreditScoring;
use App\Models\Kyc;
use App\Models\PaiementContrat;
use App\Models\User;
use App\Support\CacheReferences;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

/**
 * Validation crédit : quand le terrain a fait le KYC et un dimensionnement, l'agent crédit valide le KYC
 * (ou le renvoie à corriger) et fait le credit scoring.
 */
class CreditScoringTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('table-credit-scoring-disponible');
        $this->creerReferences();
        DB::table('agent')->insert(['id_agent' => 3, 'nom_agent' => 'Awa Crédit', 'visibilite_agent' => 1, 'role_agent' => 'credit']);
        CacheReferences::oublier(Agent::class);
        $this->actingAs(User::factory()->create());

        $this->artisan('crm:credit-scoring', ['--sans-historique' => true])->assertSuccessful();
    }

    private function dossier(bool $dimensionnement = true, int $statutKyc = Kyc::VALIDATION_A_VALIDER): Client
    {
        $client = $this->creerClient();
        $client->kycs()->create(['id_kyc' => 'K'.$client->id_client, 'num_piece_id' => 'CI0042', 'ref_client_upya' => '241798886', 'd_nais' => '1985-06-15', 'statut_validation_kyc' => $statutKyc]);
        if ($dimensionnement) {
            $client->evalSites()->create(['id_type_eval_site' => 1, 'id_prod' => 1]);
        }

        return $client->fresh();
    }

    protected function tearDown(): void
    {
        PaiementContrat::$simulation = null;

        parent::tearDown();
    }

    /** Contrat du CRM portant un n° UPYA (inséré directement : les règles du contrat sont testées ailleurs). */
    private function contratUpya(Client $client, string $numero): void
    {
        DB::table('contrat')->insert([
            'id_contrat' => 'C'.$client->id_client, 'num_contrat' => 'CT-TEST-'.$client->id_client, 'id_client' => $client->id_client,
            'date_contrat' => now()->toDateString(), 'statut' => 'actif', 'num_contrat_upya' => $numero,
        ]);
        Cache::forget('contrat-upya-disponible');
    }

    public function test_le_dossier_ne_va_au_credit_qu_avec_un_deposit_complet(): void
    {
        $client = $this->dossier();
        $this->contratUpya($client, '035164558');
        $file = fn (string $etat) => Client::query()->fileCredit($etat)->pluck('id_client')->all();

        // Aucun deposit : le dossier attend, il n'est pas dans la file du crédit
        PaiementContrat::$simulation = [];
        $this->assertSame('attente_deposit', $client->etatCredit());
        $this->assertNotNull($client->raisonScoringImpossible());
        $this->assertNotContains($client->id_client, $file('a_traiter'));
        $this->assertContains($client->id_client, $file('attente_deposit'));

        // Deposit partiel : toujours en attente
        PaiementContrat::$simulation = ['35164558' => ['statut' => 'partial_deposit', 'mt_deposit' => '5000.00']];
        $this->assertSame('attente_deposit', $client->etatCredit());
        $this->assertNotContains($client->id_client, $file('a_traiter'));

        // Deposit complet (n° écrit sans le zéro de tête dans la base des ventes) : le dossier arrive au crédit
        PaiementContrat::$simulation = ['35164558' => ['statut' => 'full_deposit', 'mt_deposit' => '25000.00']];
        $this->assertSame('kyc_a_valider', $client->etatCredit());
        $this->assertSame(25000.0, $client->deposit_paye);
        $this->assertContains($client->id_client, $file('a_traiter'));
        $this->assertNotContains($client->id_client, $file('attente_deposit'));
    }

    public function test_sans_base_des_ventes_la_regle_du_deposit_ne_bloque_pas(): void
    {
        $client = $this->dossier();

        PaiementContrat::$simulation = null; // VENTES_DB_PASSWORD vide en test
        $this->assertFalse(PaiementContrat::verificationActive());
        $this->assertSame('kyc_a_valider', $client->etatCredit());
        $this->assertContains($client->id_client, Client::query()->fileCredit('a_traiter')->pluck('id_client')->all());
    }

    private function actionKyc(string $nom): TestAction
    {
        return TestAction::make($nom)->schemaComponent('section-kyc', schema: 'infolist');
    }

    private function scorings(Client $client)
    {
        return Livewire::test(CreditScoringsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
    }

    public function test_le_dossier_arrive_au_credit_apres_kyc_et_dimensionnement(): void
    {
        $kycSeul = $this->dossier(dimensionnement: false);
        $this->assertSame('attente_terrain', $kycSeul->etatCredit());
        $this->assertStringContainsString('dimensionnement', $kycSeul->raisonScoringImpossible());
        $this->scorings($kycSeul)->assertActionDisabled(TestAction::make('ajouterScoring')->table());

        $pret = $this->dossier();
        $pret->evalSites()->update(['statut_eval_site' => 1]);
        $this->assertSame('kyc_a_valider', $pret->etatCredit());
        $this->assertNull($pret->raisonScoringImpossible());

        $this->get(ClientResource::getUrl('view', ['record' => $pret]))
            ->assertOk()
            ->assertSee('Créer le contrat') // le contrat (avec son n° UPYA) se fait avant le passage au crédit
            ->assertSee('KYC à valider (crédit)');

        $this->assertEquals([$pret->id_client], Client::query()->fileCredit('a_traiter')->pluck('id_client')->all());
    }

    public function test_l_agent_credit_valide_le_kyc(): void
    {
        $client = $this->dossier();

        Livewire::test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction($this->actionKyc('validerKycCredit'), data: ['decision' => 'valide', 'id_agent' => 3])
            ->assertHasNoActionErrors();

        $kyc = $client->fresh()->kyc;
        $this->assertTrue($kyc->estValideParLeCredit());
        $this->assertSame(3, (int) $kyc->id_agent_validation_kyc);
        $this->assertNotNull($kyc->date_validation_kyc);
        $this->assertSame('scoring_a_faire', $client->fresh()->etatCredit());
    }

    public function test_le_credit_ne_valide_pas_un_client_hors_age(): void
    {
        $client = $this->dossier();
        $client->kyc->update(['d_nais' => '1940-05-01', 'statut_validation_kyc' => Kyc::VALIDATION_A_VALIDER]);

        Livewire::test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction($this->actionKyc('validerKycCredit'), data: ['decision' => 'valide', 'id_agent' => 3])
            ->assertNotified('KYC non validable');

        $this->assertFalse($client->fresh()->kyc->estValideParLeCredit());
    }

    public function test_kyc_renvoye_a_corriger_puis_corrige_par_le_terrain(): void
    {
        $client = $this->dossier();

        // Le motif est obligatoire pour un renvoi
        Livewire::test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction($this->actionKyc('validerKycCredit'), data: ['decision' => 'a_corriger', 'id_agent' => 3, 'motif' => null])
            ->assertHasActionErrors(['motif' => 'required']);

        Livewire::test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction($this->actionKyc('validerKycCredit'), data: ['decision' => 'a_corriger', 'id_agent' => 3, 'motif' => 'Photo du recto illisible'])
            ->assertHasNoActionErrors();

        $client = $client->fresh();
        $this->assertSame('kyc_a_corriger', $client->etatCredit());
        $this->assertEquals([$client->id_client], Client::query()->fileCredit('kyc_a_corriger')->pluck('id_client')->all());
        $this->assertCount(0, Client::query()->fileCredit('a_traiter')->get());

        $this->get(ClientResource::getUrl('view', ['record' => $client]))
            ->assertSee('KYC à corriger')
            ->assertSee('Photo du recto illisible');

        // L'agent terrain corrige le KYC : il repart au crédit
        $client->kyc->update(['photo_id_face1' => 'kyc/recto-net.jpg']);
        $this->assertSame(Kyc::VALIDATION_A_VALIDER, (int) $client->fresh()->kyc->statut_validation_kyc);
        $this->assertSame('kyc_a_valider', $client->fresh()->etatCredit());
    }

    public function test_un_kyc_valide_puis_modifie_repasse_a_valider(): void
    {
        $client = $this->dossier(statutKyc: Kyc::VALIDATION_VALIDE);

        $client->kyc->update(['num_piece_id' => 'CI9999']);

        $this->assertSame(Kyc::VALIDATION_A_VALIDER, (int) $client->fresh()->kyc->statut_validation_kyc);
    }

    public function test_credit_scoring_depuis_la_fiche(): void
    {
        $client = $this->dossier(statutKyc: Kyc::VALIDATION_VALIDE);
        $this->contratUpya($client, '241798886');
        PaiementContrat::$simulation = ['241798886' => ['statut' => 'full_deposit', 'mt_deposit' => '45000.00']];

        $this->scorings($client)
            ->assertActionEnabled(TestAction::make('ajouterScoring')->table())
            ->mountAction(TestAction::make('ajouterScoring')->table())
            ->assertSchemaStateSet(['num_cni' => 'CI0042', 'num_contrat_upya' => '241798886'], 'mountedActionSchema0') // repris du contrat
            ->setActionData([
                'id_agent' => 3, CreditScoring::CHAMP_APPEL => '1', 'q13_cs' => '22500', 'q14_cs' => '855000',
                CreditScoring::CHAMP_DECISION => '1', 'q47_cs' => 'RAS, commande validée',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $scoring = $client->creditScorings()->firstOrFail();
        $this->assertSame('K'.$client->id_client, $scoring->id_kyc);
        $this->assertSame('CI0042', $scoring->num_cni);
        $this->assertSame('241798886', $scoring->num_contrat_upya); // n° UPYA du contrat, enregistré avec le scoring
        $this->assertSame('45000', $scoring->q11_cs); // dépôt initial : plus demandé, repris du deposit
        $this->assertSame('22500', $scoring->q13_cs); // montant par mois, saisi
        $this->assertSame(now()->toDateString(), $scoring->q43_cs); // date de validation, proposée pour un dossier validé
        $this->assertTrue($scoring->estValide());
        $this->assertSame('Validé', $scoring->libelleDecision());
        $this->assertSame('Joint', $scoring->libelleReponse(CreditScoring::CHAMP_APPEL));
        $this->assertSame('valide', $client->fresh()->etatCredit());

        $this->scorings($client->fresh())->assertSee('Validé')->assertSee('Joint')->assertSee('Awa Crédit');
    }

    public function test_seuls_les_agents_credit_sont_proposes(): void
    {
        $this->assertSame([3 => 'Awa Crédit'], Agent::optionsCredit());
    }

    public function test_page_validation_credit(): void
    {
        $aValider = $this->dossier();
        $aScorer = $this->dossier(statutKyc: Kyc::VALIDATION_VALIDE);
        $enCours = $this->dossier(statutKyc: Kyc::VALIDATION_VALIDE);
        $injoignable = $this->dossier(statutKyc: Kyc::VALIDATION_VALIDE);
        $miseAJour = $this->dossier(statutKyc: Kyc::VALIDATION_VALIDE);
        $invalide = $this->dossier(statutKyc: Kyc::VALIDATION_VALIDE);
        $valide = $this->dossier(statutKyc: Kyc::VALIDATION_VALIDE);
        $renvoye = $this->dossier(statutKyc: Kyc::VALIDATION_A_CORRIGER);
        $terrain = $this->dossier(dimensionnement: false);

        $scoring = fn (Client $client, string $decision, string $date) => $client->creditScorings()->create([
            CreditScoring::CHAMP_DECISION => $decision, 'date_cs' => $date, 'heure_cs' => '10:00:00',
        ]);
        $scoring($enCours, '6', '2026-10-01');                 // En cours : reste à traiter
        $scoring($injoignable, '1', '2026-09-01');             // validé une fois… (KYC validé + scoring validé = Validé)
        $injoignable->creditScorings()->update([CreditScoring::CHAMP_DECISION => '4']); // …finalement en cours
        $scoring($injoignable, '8', '2026-10-02');             // dernier appel : injoignable
        $scoring($miseAJour, '8', '2026-10-01');
        $scoring($miseAJour, '5', '2026-10-03');               // le plus récent compte : en attente de mise à jour
        $scoring($invalide, '2', '2026-10-01');
        $scoring($valide, '1', '2026-10-01');

        $page = fn (string $onglet) => Livewire::test(ValidationCredit::class)->set('activeTab', $onglet);

        $page('a_traiter')->assertOk()
            ->assertCanSeeTableRecords([$aValider, $aScorer, $enCours])
            ->assertCanNotSeeTableRecords([$injoignable, $miseAJour, $invalide, $valide, $renvoye, $terrain]);
        $page('injoignable')->assertCanSeeTableRecords([$injoignable])->assertCanNotSeeTableRecords([$miseAJour, $aValider]);
        $page('mise_a_jour')->assertCanSeeTableRecords([$miseAJour])->assertCanNotSeeTableRecords([$injoignable]);
        $page('invalide')->assertCanSeeTableRecords([$invalide])->assertCanNotSeeTableRecords([$valide]);
        $page('valide')->assertCanSeeTableRecords([$valide])->assertCanNotSeeTableRecords([$invalide, $aScorer]);

        $this->assertSame('3', ValidationCredit::getNavigationBadge());
    }

    public function test_import_de_l_historique(): void
    {
        $client = $this->dossier();
        $dossier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cs_'.uniqid();
        mkdir($dossier);

        // q41 = statut de l'appel (1 = Joint), q42 = statut du CDS 2 (1 = Validé)
        $entete = ['id_cs', 'id_activite', 'num_cni', 'id_src_eau', 'id_periodicite', 'id_prospect', 'id_agent', 'date_cs', 'heure_cs', 'q41_cs', 'q42_cs', 'q47_cs', 'visibilite_cs'];
        $lignes = [
            ['1', '1', 'CI0042', '3', 'NULL', $client->id_client, '793', '2025-02-01', '16:10:43', '1', '6', "A RAPPELER, rappel\ndemain", '1'],
            ['2', '2', 'CI0042', '3', 'NULL', $client->id_client, '793', '2025-02-03', '09:00:00', '1', '1', 'RAS, COMMANDE VALIDEE', '1'],
            ['3', 'NULL', 'XX', '1', 'NULL', 'inconnu', '3', '2025-03-01', 'NULL', '2', '8', 'Injoignable', 'NULL'],
        ];
        $flux = fopen($dossier.DIRECTORY_SEPARATOR.'credit_scoring.csv', 'w');
        fputcsv($flux, $entete, escape: '');
        foreach ($lignes as $ligne) {
            fputcsv($flux, $ligne, escape: '');
        }
        fclose($flux);

        $this->artisan('crm:credit-scoring', ['dossier' => $dossier])->assertSuccessful();

        $this->assertSame(3, CreditScoring::count());
        $this->assertSame(2, $client->creditScorings()->count());
        $this->assertNull(CreditScoring::find('3')->id_client); // prospect introuvable : gardé pour l'historique
        $this->assertSame("A RAPPELER, rappel\ndemain", CreditScoring::find('1')->q47_cs);

        // Agent de l'historique créé comme agent crédit ; agent existant passé au rôle crédit
        $this->assertSame('credit', DB::table('agent')->where('id_agent', 793)->value('role_agent'));

        // Commande validée dans l'historique : le KYC est validé, à la date et par l'agent de la validation
        $kyc = $client->fresh()->kyc;
        $this->assertTrue($kyc->estValideParLeCredit());
        $this->assertSame(793, (int) $kyc->id_agent_validation_kyc);
        $this->assertSame('2025-02-03', $kyc->date_validation_kyc->toDateString());
        $this->assertSame('valide', $client->fresh()->etatCredit());

        // Relancer sans --remplacer ne réimporte pas
        $this->artisan('crm:credit-scoring', ['dossier' => $dossier])->assertSuccessful();
        $this->assertSame(3, CreditScoring::count());
    }

    /** Correction : les validations posées par l'import sur « appel joint » sont recalculées sur « CDS 2 validé ». */
    public function test_recalcul_des_validations_kyc_et_mise_a_jour_des_questions(): void
    {
        $joint = $this->dossier();     // appel joint, CDS 2 non validé
        $valide = $this->dossier();    // CDS 2 validé
        $manuel = $this->dossier();    // validé dans le CRM, sans scoring ce jour-là
        DB::table('credit_scoring')->insert([
            ['id_cs' => 'A', 'id_client' => $joint->id_client, 'id_agent' => 3, 'date_cs' => '2025-05-02', 'q41_cs' => '1', 'q42_cs' => '6'],
            ['id_cs' => 'B', 'id_client' => $valide->id_client, 'id_agent' => 3, 'date_cs' => '2025-06-10', 'q41_cs' => '1', 'q42_cs' => '1'],
        ]);
        // État laissé par l'ancien import (validation sur q41)
        $joint->kyc->update(['statut_validation_kyc' => 1, 'date_validation_kyc' => '2025-05-02', 'id_agent_validation_kyc' => 3]);
        $manuel->kyc->update(['statut_validation_kyc' => 1, 'date_validation_kyc' => '2026-10-20 10:00:00', 'id_agent_validation_kyc' => 3]);

        // Ancien formulaire : une question « Décision » sur q41, une section « Questionnaire »
        DB::table('formulaire_champ')->where('formulaire', 'credit_scoring')->where('cle', 'q41_cs')->update(['libelle' => 'Décision', 'section' => 'Questionnaire']);

        $this->artisan('crm:credit-scoring', ['--mettre-a-jour-questions' => true, '--recalculer-validations-kyc' => true])->assertSuccessful();

        $this->assertFalse($joint->fresh()->kyc->estValideParLeCredit());
        $this->assertTrue($valide->fresh()->kyc->estValideParLeCredit());
        $this->assertSame('2025-06-10', $valide->fresh()->kyc->date_validation_kyc->toDateString());
        $this->assertTrue($manuel->fresh()->kyc->estValideParLeCredit()); // validation faite dans le CRM : conservée

        $this->assertSame('Statut de l\'appel', DB::table('formulaire_champ')->where('formulaire', 'credit_scoring')->where('cle', 'q41_cs')->value('libelle'));
        $this->assertSame('Statut du CDS 2', DB::table('formulaire_champ')->where('formulaire', 'credit_scoring')->where('cle', 'q42_cs')->value('libelle'));
        $this->assertFalse(DB::table('formulaire_section')->where('formulaire', 'credit_scoring')->where('nom', 'Questionnaire')->exists());
    }
}
