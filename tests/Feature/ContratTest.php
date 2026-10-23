<?php

namespace Tests\Feature;

use App\Contrats\CreerContrat;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ContratsRelationManager;
use App\Models\Client;
use App\Models\Contrat;
use App\Models\EvalSite;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

/**
 * Règles des contrats (base SQLite de test) : un contrat = un TDH validé + le KYC valable du même client,
 * un seul contrat par TDH. En production, le trigger contrat_verifier_regles applique les mêmes règles.
 */
class ContratTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('table-contrat-disponible');
        Cache::forget('table-credit-scoring-disponible');
        $this->creerReferences();
        $this->actingAs(User::factory()->create());
    }

    private function clientAvecKyc(array $kyc = []): Client
    {
        $client = $this->creerClient();
        // KYC validé par le crédit (condition du contrat)
        $client->kycs()->create($kyc + ['id_kyc' => 'K'.$client->id_client, 'num_piece_id' => 'CI001', 'id_type_vente' => 1, 'statut_validation_kyc' => 1]);

        return $client->fresh();
    }

    private function tdh(Client $client, bool $validee = true): EvalSite
    {
        return $client->evalSites()->create([
            'id_type_eval_site' => 1, 'id_prod' => 1, 'tdh' => '25',
            'statut_eval_site' => $validee ? EvalSite::STATUT_VALIDEE : EvalSite::STATUT_A_VALIDER,
        ]);
    }

    private function refus(callable $creation, string $extrait): void
    {
        try {
            $creation();
            $this->fail('Le contrat aurait dû être refusé.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($extrait, collect($e->errors())->flatten()->implode(' '));
        }
    }

    public function test_un_contrat_valide_est_accepte(): void
    {
        $client = $this->clientAvecKyc(['date_expir_doc' => now()->addYear()->toDateString()]);
        $tdh = $this->tdh($client);

        $contrat = app(CreerContrat::class)($client, ['id_eval' => $tdh->id_eval_site, 'montant' => '855000', 'id_type_vente' => 1]);

        $this->assertSame($client->id_client, $contrat->id_client);
        $this->assertSame($client->kyc->id_kyc, $contrat->id_kyc);
        $this->assertSame('CT-'.now()->format('Y').'-000001', $contrat->num_contrat);
        $this->assertSame($contrat->id_contrat, $tdh->contrat->id_contrat);
        $this->assertSame(0, $client->evalSites()->convertibles()->count());
    }

    public function test_deuxieme_contrat_sur_le_meme_tdh_refuse(): void
    {
        $client = $this->clientAvecKyc();
        $tdh = $this->tdh($client);
        app(CreerContrat::class)($client, ['id_eval' => $tdh->id_eval_site]);

        $this->refus(fn () => app(CreerContrat::class)($client, ['id_eval' => $tdh->id_eval_site]), 'déjà donné le contrat');
        $this->assertSame(1, Contrat::count());
    }

    public function test_tdh_non_valide_refuse(): void
    {
        $client = $this->clientAvecKyc();
        $tdh = $this->tdh($client, validee: false);

        $this->refus(fn () => app(CreerContrat::class)($client, ['id_eval' => $tdh->id_eval_site]), 'n\'est pas validé');
        $this->assertFalse($client->peutCreerContrat());
        $this->assertSame(0, Contrat::count());
    }

    public function test_kyc_non_valable_refuse(): void
    {
        $expire = $this->clientAvecKyc(['date_expir_doc' => '2025-01-01']);
        $tdh = $this->tdh($expire);
        $this->refus(fn () => app(CreerContrat::class)($expire, ['id_eval' => $tdh->id_eval_site]), 'mettez à jour le KYC');

        $sansKyc = $this->creerClient();
        $tdh2 = $this->tdh($sansKyc);
        $this->refus(fn () => app(CreerContrat::class)($sansKyc, ['id_eval' => $tdh2->id_eval_site]), 'pas de KYC');

        $inactif = $this->creerClient();
        $inactif->kycs()->create(['id_kyc' => 'KINACTIF', 'num_piece_id' => 'X', 'visibilite_kyc' => 0]);
        $tdh3 = $this->tdh($inactif);
        $this->refus(fn () => app(CreerContrat::class)($inactif, ['id_eval' => $tdh3->id_eval_site, 'id_kyc' => 'KINACTIF']), 'n\'est pas actif');

        $this->assertSame(0, Contrat::count());
    }

    /** Le contrat se crée avant la validation du KYC par le crédit. */
    public function test_contrat_avant_la_validation_du_kyc_par_le_credit(): void
    {
        foreach ([0, 2] as $statut) { // à valider, ou renvoyé à corriger
            $client = $this->clientAvecKyc(['statut_validation_kyc' => $statut]);
            $tdh = $this->tdh($client);

            $this->assertTrue($client->peutCreerContrat());
            $this->assertNotNull(app(CreerContrat::class)($client, ['id_eval' => $tdh->id_eval_site]));
        }

        $this->assertSame(2, Contrat::count());
    }

    public function test_tdh_ou_kyc_d_un_autre_client_refuse(): void
    {
        $client = $this->clientAvecKyc();
        $autre = $this->clientAvecKyc();
        $tdhAutre = $this->tdh($autre);

        $this->refus(fn () => app(CreerContrat::class)($client, ['id_eval' => $tdhAutre->id_eval_site]), 'appartient à un autre client');

        $tdh = $this->tdh($client);
        $this->refus(fn () => app(CreerContrat::class)($client, ['id_eval' => $tdh->id_eval_site, 'id_kyc' => $autre->kyc->id_kyc]), 'appartient à un autre client');

        $this->assertSame(0, Contrat::count());
    }

    public function test_plusieurs_kits_un_kyc_plusieurs_tdh_un_contrat_par_tdh(): void
    {
        $client = $this->clientAvecKyc();
        $tdh1 = $this->tdh($client);
        $tdh2 = $this->tdh($client);
        $this->tdh($client, validee: false);

        $this->assertSame(2, $client->evalSites()->convertibles()->count());

        app(CreerContrat::class)($client, ['id_eval' => $tdh1->id_eval_site]);
        app(CreerContrat::class)($client, ['id_eval' => $tdh2->id_eval_site]);

        $this->assertSame(2, $client->contrats()->count());
        $this->assertSame(1, $client->contrats()->distinct()->count('id_kyc'));
        $this->assertFalse($client->fresh()->peutCreerContrat());
        $this->assertStringContainsString('Aucun dimensionnement validé sans contrat', $client->fresh()->raisonContratImpossible());
    }

    public function test_page_du_contrat_et_lien_vers_le_client(): void
    {
        $client = $this->clientAvecKyc();
        $contrat = app(CreerContrat::class)($client, ['id_eval' => $this->tdh($client)->id_eval_site, 'num_contrat_upya' => '241798886']);
        $urlContrat = \App\Filament\Resources\Contrats\ContratResource::getUrl('view', ['record' => $contrat]);
        $urlClient = \App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $client]);

        // Page du contrat : résumé, parcours, et le nom du titulaire ouvre la fiche client
        $this->get($urlContrat)->assertOk()
            ->assertSee('Résumé du contrat')
            ->assertSee('Parcours client')
            ->assertSee($contrat->num_contrat)
            ->assertSee('241798886')
            ->assertSee($urlClient, false);

        // Fiche client : le contrat en carte, qui ouvre la page du contrat ; liste des contrats aussi
        $this->get($urlClient)->assertOk()->assertSee('Informations du profil')->assertSee($urlContrat, false);
        // Liste des contrats (n° CRM masqué par défaut, colonnes à la UPYA) : elle s'ouvre
        $this->get(\App\Filament\Resources\Contrats\ContratResource::getUrl())->assertOk();
    }

    public function test_creer_un_contrat_depuis_la_fiche(): void
    {
        $client = $this->clientAvecKyc();
        $tdh = $this->tdh($client);

        $rm = Livewire::test(ContratsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
            ->assertActionEnabled(TestAction::make('creerContrat')->table())
            ->mountAction(TestAction::make('creerContrat')->table())
            ->assertSchemaStateSet(['id_eval' => $tdh->id_eval_site, 'id_kyc' => $client->kyc->id_kyc, 'id_prod' => 1], 'mountedActionSchema0')
            ->setActionData(['montant' => '855 000', 'num_contrat_upya' => 'CI0042'])
            ->callMountedAction()
            ->assertHasActionErrors(['num_contrat_upya' => 'regex']) // 7 à 9 chiffres
            ->setActionData(['num_contrat_upya' => '241798886'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $contrat = $client->contrats()->first();
        $this->assertNotNull($contrat);
        $this->assertSame('855000.00', $contrat->montant);
        $this->assertSame('241798886', $contrat->num_contrat_upya);

        // Plus aucun TDH convertible : le bouton est désactivé, avec la raison en infobulle
        Livewire::test(ContratsRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => ViewClient::class])
            ->assertActionDisabled(TestAction::make('creerContrat')->table())
            ->assertSee($contrat->num_contrat);

        // Un n° UPYA ne sert qu'à un seul contrat
        $autre = $this->clientAvecKyc();
        try {
            app(CreerContrat::class)($autre, ['id_eval' => $this->tdh($autre)->id_eval_site, 'num_contrat_upya' => '241798886']);
            $this->fail('Le n° UPYA déjà utilisé aurait dû être refusé.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('déjà utilisé', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame(0, $autre->contrats()->count());
    }

    public function test_la_liste_des_clients_affiche_le_nombre_de_contrats(): void
    {
        $client = $this->clientAvecKyc();
        app(CreerContrat::class)($client, ['id_eval' => $this->tdh($client)->id_eval_site]);
        app(CreerContrat::class)($client, ['id_eval' => $this->tdh($client)->id_eval_site]);

        Livewire::test(\App\Filament\Resources\Clients\Widgets\CartesClients::class)
            ->assertSee('Contrats')
            ->assertSeeHtml('<span class="gr-kpi-valeur">2</span>')
            ->assertDontSee('Installés');
    }

    public function test_message_d_erreur_du_trigger_affiche_proprement(): void
    {
        $erreur = new \Illuminate\Database\QueryException('pgsql', 'insert into contrat …', [], new \PDOException('x'));
        $erreur->errorInfo = ['P0001', 7, "ERROR:  L'évaluation du site abc n'est pas validée : validez le TDH avant de créer le contrat.\nCONTEXT:  PL/pgSQL function contrat_verifier_regles()"];

        $this->assertSame("L'évaluation du site abc n'est pas validée : validez le TDH avant de créer le contrat.", CreerContrat::messageBase($erreur));
    }
}
