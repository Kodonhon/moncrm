<?php

namespace Tests\Feature;

use App\Filament\Resources\PreparationInstallations\Pages\ListePreparationInstallation;
use App\Models\Client;
use App\Models\CreditScoring;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

/** Préparation installation : les nouveaux arrivés (jamais appelés) ne sont pas mêlés aux dossiers déjà traités. */
class PreparationInstallationTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('table-credit-scoring-disponible');
        Cache::forget('table-prep-inst-disponible');
        $this->creerReferences();
        $this->actingAs(User::factory()->create());
    }

    /** Client validé au credit scoring, avec éventuellement un appel du CX (résultat d'origine). */
    private function dossier(?int $resultatAppel = null): CreditScoring
    {
        $client = $this->creerClient();
        $scoring = $client->creditScorings()->create([
            CreditScoring::CHAMP_DECISION => CreditScoring::DECISION_VALIDEE, 'date_cs' => '2026-10-01',
        ]);

        if ($resultatAppel !== null) {
            DB::table('prep_inst')->insert([
                'id_prep' => 'P'.$client->id_client, 'id_client' => $client->id_client,
                'date_prep' => '2026-10-10', 'resul_prep_inst' => $resultatAppel,
            ]);
        }

        return $scoring;
    }

    public function test_les_nouveaux_arrives_sont_separes_des_dossiers_deja_traites(): void
    {
        $nouveau = $this->dossier();
        $injoignable = $this->dossier(1);   // à rappeler
        $neDecrochePas = $this->dossier(6); // à rappeler
        $pret = $this->dossier(2);

        $page = fn (string $onglet) => Livewire::test(ListePreparationInstallation::class)->set('activeTab', $onglet);

        // Onglet par défaut : les nouveaux arrivés seulement
        Livewire::test(ListePreparationInstallation::class)
            ->assertCanSeeTableRecords([$nouveau])
            ->assertCanNotSeeTableRecords([$injoignable, $neDecrochePas, $pret]);

        $page('a_rappeler')
            ->assertCanSeeTableRecords([$injoignable, $neDecrochePas])
            ->assertCanNotSeeTableRecords([$nouveau, $pret]);

        $page('pret')->assertCanSeeTableRecords([$pret])->assertCanNotSeeTableRecords([$nouveau, $injoignable]);
    }

    public function test_les_clients_prets_attendent_un_kit_chez_le_shop_manager(): void
    {
        $nouveau = $this->dossier();
        $aRappeler = $this->dossier(1);
        $pretGagnoa = $this->dossier(2);                      // localité 2 : SHOP GAGNOA (5)
        $pretDaloa = $this->dossier(2);
        Client::whereKey($pretDaloa->id_client)->update(['id_localite_commune' => 1]); // DALOA : SHOP DALOA (9)

        Livewire::test(\App\Filament\Pages\AssignationKits::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$pretGagnoa, $pretDaloa])
            ->assertCanNotSeeTableRecords([$nouveau, $aRappeler])
            ->assertSee('SHOP GAGNOA')
            ->filterTable('shop', 9)
            ->assertCanSeeTableRecords([$pretDaloa])
            ->assertCanNotSeeTableRecords([$pretGagnoa]);

        Cache::forget('kits-en-attente');
        $this->assertSame('2', \App\Filament\Pages\AssignationKits::getNavigationBadge());
    }

    public function test_le_technicien_fait_le_rapport_d_installation_depuis_prets_pour_l_installation(): void
    {
        foreach (['table-assignation-kit-disponible', 'table-installation-disponible'] as $cle) {
            Cache::forget($cle);
        }
        $pret = $this->dossier(2);
        \App\Models\AssignationKit::create(['id_client' => $pret->id_client, 'num_serie_controleur' => 'CTRL-9', 'num_serie_pompe' => 'POMPE-9',
            'num_serie_panneau_1' => 'PAN-9', 'statut' => 'assigne']);
        $prets = fn () => Livewire::test(\App\Filament\Pages\PretsInstallation::class);

        // Le nom du client ouvre sa fiche
        $prets()->assertSee(\App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $pret->id_client]), false);

        // Non installé : la raison est obligatoire, le client reste dans la liste
        $prets()->callTableAction('rapportInstallation', $pret, data: ['systeme_installe' => '0'])
            ->assertHasTableActionErrors(['raison_non_installation' => 'required']);

        // Installé : rapport enregistré avec les n° de série du kit (pré-remplis), le client sort de la liste
        $prets()->callTableAction('rapportInstallation', $pret, data: ['systeme_installe' => '1', 'position_panneaux' => 'Sur le toit'])
            ->assertHasNoTableActionErrors();

        $rapport = \App\Models\Installation::where('id_client', $pret->id_client)->firstOrFail();
        $this->assertSame(1, (int) $rapport->systeme_installe);
        $this->assertSame('POMPE-9', $rapport->num_serie_pompe);
        $this->assertSame('CTRL-9', $rapport->code_controleur);
        $this->assertSame('Sur le toit', $rapport->donnees_origine['Position des panneaux']);

        $prets()->assertCanNotSeeTableRecords([$pret]);
        Livewire::test(\App\Filament\Pages\AssignationKits::class)->set('activeTab', 'installe')->assertCanSeeTableRecords([$pret]);
    }

    public function test_le_shop_manager_assigne_le_kit_puis_le_client_est_pret_pour_l_installation(): void
    {
        Cache::forget('table-assignation-kit-disponible');
        $pret = $this->dossier(2);
        $autre = $this->dossier(2);
        // Deux entrées du menu : « Assignation des kits » et « Prêts pour l'installation »
        $page = fn (string $liste = 'a_assigner') => Livewire::test($liste === 'pret_installation'
            ? \App\Filament\Pages\PretsInstallation::class
            : \App\Filament\Pages\AssignationKits::class);
        $kit = ['num_serie_controleur' => 'CTRL-001', 'num_serie_pompe' => 'POMPE-777', 'num_serie_panneau_1' => 'PAN-1', 'num_serie_panneau_2' => 'PAN-2'];

        // Assignation : les numéros de série sont obligatoires (sauf le 2e panneau)
        $page()->callTableAction('assignerKit', $pret, data: ['num_serie_pompe' => ''])
            ->assertHasTableActionErrors(['num_serie_controleur' => 'required', 'num_serie_pompe' => 'required', 'num_serie_panneau_1' => 'required']);
        $page()->callTableAction('assignerKit', $pret, data: $kit)->assertHasNoTableActionErrors();

        $assignation = \App\Models\AssignationKit::where('id_client', $pret->id_client)->firstOrFail();
        $this->assertSame('POMPE-777', $assignation->num_serie_pompe);
        $this->assertSame('assigne', $assignation->statut);

        // Le client passe de « À assigner » à « Prêts pour l'installation », avec ses numéros de série
        $page()->assertCanSeeTableRecords([$autre])->assertCanNotSeeTableRecords([$pret]);
        $page('pret_installation')->assertCanSeeTableRecords([$pret])->assertCanNotSeeTableRecords([$autre])->assertSee('POMPE-777');

        // La même pompe ne peut pas être assignée à un autre client
        $page()->callTableAction('assignerKit', $autre, data: ['num_serie_pompe' => 'pompe-777'] + $kit)
            ->assertHasTableActionErrors(['num_serie_pompe']);

        // « Assignation des kits » a aussi ses onglets : non assignés / assignés non installés / assignés installés
        $onglet = fn (string $o) => Livewire::test(\App\Filament\Pages\AssignationKits::class)->set('activeTab', $o);
        $onglet('pret_installation')->assertCanSeeTableRecords([$pret])->assertCanNotSeeTableRecords([$autre]);
        $onglet('installe')->assertCanNotSeeTableRecords([$pret]);

        DB::table('installation')->insert(['id_installation' => 'INST1', 'id_client' => $pret->id_client, 'systeme_installe' => 1, 'date_installation' => '2026-10-20']);
        Cache::forget('table-installation-disponible');
        $onglet('installe')->assertCanSeeTableRecords([$pret])->assertSee('20/10/2026')
            ->assertTableActionHidden('annulerKit', $pret);                         // installé : plus d'annulation
        $onglet('pret_installation')->assertCanNotSeeTableRecords([$pret]);         // sorti des « non installés »
        $page('pret_installation')->assertCanNotSeeTableRecords([$pret]);           // et de la page du technicien
        DB::table('installation')->where('id_installation', 'INST1')->delete();

        // Annulation : le client repasse dans « À assigner »
        $page('pret_installation')->callTableAction('annulerKit', $pret);
        $this->assertSame('annule', $assignation->fresh()->statut);
        $page()->assertCanSeeTableRecords([$pret, $autre]);
    }
}
