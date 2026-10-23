<?php

namespace Tests\Feature;

use App\Filament\Clusters\Parametres\Resources\Pages\GererProduits;
use App\Models\CreditScoring;
use App\Models\FormulaireChamp;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

/** Catalogue des produits (produit.csv) : import, commission, et « Pompe proposée » du credit scoring. */
class ProduitTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('produit-commission-disponible');
        Cache::forget('table-credit-scoring-disponible');
        $this->creerReferences();
        DB::table('produit')->delete();
        $this->actingAs(User::factory()->create());
    }

    public function test_import_du_catalogue_et_ecran_parametres(): void
    {
        $dossier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'produits_'.uniqid();
        mkdir($dossier);
        file_put_contents($dossier.DIRECTORY_SEPARATOR.'produit.csv', implode("\n", [
            '"id_prod","lib_prod","debit_prod","visibilite_prod","desc_prod","montant_com_commer"',
            '11,"KOMDOGO",NULL,1,"KIT-KOMDOGO","20000"',
            '12,"NIKLA",NULL,1,"KIT-NIKLA","20000"',
        ]));

        $this->artisan('crm:importer', ['dossier' => $dossier, '--seulement' => 'produit'])->assertSuccessful();

        $this->assertSame('NIKLA', Produit::find(12)->lib_prod);
        $this->assertEquals(20000, Produit::find(12)->montant_com_commer);

        Livewire::test(GererProduits::class)
            ->assertSee('NIKLA')
            ->assertSee('KIT-NIKLA')
            ->assertSee('20 000 FCFA');
    }

    public function test_la_pompe_proposee_du_scoring_affiche_le_nom_du_produit(): void
    {
        DB::table('produit')->insert(['id_prod' => 12, 'lib_prod' => 'NIKLA', 'visibilite_prod' => 1]);
        $this->artisan('crm:credit-scoring', ['--sans-historique' => true])->assertSuccessful();

        $champ = FormulaireChamp::pour('credit_scoring')->firstWhere('cle', 'q12_cs');
        $this->assertSame('produit', $champ->source_liste);

        $scoring = new CreditScoring(['q12_cs' => '12']);
        $this->assertSame('NIKLA', $scoring->libelleReponse('q12_cs'));
    }
}
