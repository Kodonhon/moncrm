<?php

namespace Tests\Feature;

use App\Filament\Pages\AssignationKits;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\PreparationInstallations\Pages\ListePreparationInstallation;
use App\Models\Client;
use App\Models\CreditScoring;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

/** Rapports d'installation : un client installé sort de toutes les listes en attente d'action. */
class InstallationTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['table-credit-scoring-disponible', 'table-prep-inst-disponible', 'table-installation-disponible', 'kits-en-attente', 'file-credit-a-traiter'] as $cle) {
            Cache::forget($cle);
        }
        $this->creerReferences();
        $this->actingAs(User::factory()->create());
    }

    /** Client avec KYC à valider, dimensionnement et scoring validé (dans toutes les listes), éventuellement prêt au CX. */
    private function dossier(string $piece, ?string $telephone = null, bool $pretCx = false): Client
    {
        $client = $this->creerClient($telephone ? ['contact1_client' => $telephone] : []);
        $client->kycs()->create(['id_kyc' => 'K'.$client->id_client, 'num_piece_id' => $piece, 'd_nais' => '1985-01-01']);
        $client->evalSites()->create(['id_type_eval_site' => 1]);
        $client->creditScorings()->create([CreditScoring::CHAMP_DECISION => CreditScoring::DECISION_VALIDEE, 'date_cs' => '2026-10-01']);
        if ($pretCx) {
            DB::table('prep_inst')->insert(['id_prep' => 'P'.$client->id_client, 'id_client' => $client->id_client, 'date_prep' => '2026-10-05', 'resul_prep_inst' => 2]);
        }

        return $client->fresh();
    }

    private function export(array $lignes): string
    {
        $entete = ['ID installation', 'Date d\'installation', 'Numéro de CNI du client', 'contact_client', 'Type de produit installé',
            'Installation assignée à', 'Le KIT a t-il été reçu par le client ?', 'Photo du client avec sont equipement',
            'Numero de serie de la pompe', 'Le système a-t-il été installé ?', 'Si non, pourqoui ?'];
        $fichier = tempnam(sys_get_temp_dir(), 'inst').'.csv';
        $flux = fopen($fichier, 'w');
        fwrite($flux, "\u{FEFF}");
        fputcsv($flux, $entete, ';', '"', '');
        foreach ($lignes as $ligne) {
            fputcsv($flux, $ligne, ';', '"', '');
        }
        fclose($flux);

        return $fichier;
    }

    public function test_import_et_sortie_des_listes_en_attente(): void
    {
        $parPhoto = $this->dossier('CI0001', pretCx: true);
        $parPiece = $this->dossier('CI0002', pretCx: true);
        $parTelephone = $this->dossier('CI0003', '2250707070707', pretCx: true);
        $nonInstalle = $this->dossier('CI0004', pretCx: true);
        $enAttente = $this->dossier('CI0005', pretCx: true);

        $photo = 'https://www.appsheet.com/image/getimageurl?tableName=ta_installation&fileName=PHOTO_APP%2FINSTALLATION%2F'.$parPhoto->id_client.'CI0001%2Fx.jpg';
        $fichier = $this->export([
            ['a1', '25/03/2026', 'CI0001', '', 'SANDJI PLUS', 'KOUAME Wilfried', 'OUI', $photo, '864 107 079 857 460', 'OUI', ''],
            ['a2', '22/01/2026', 'CI0002', '', 'SANDJI PLUS MAX', 'KOUAKOU Louis', 'OUI', '', '', 'OUI', ''],
            ['a3', '10/02/2026', 'CI9999', '2 250 707 070 707,00', 'SANDJI PLUS', 'BINI Obed', 'OUI', '', '', 'OUI', ''],
            ['a4', '11/02/2026', 'CI0004', '', 'SANDJI PLUS', 'BINI Obed', 'NON', '', '', 'NON', 'Site pas prêt'],
            ['a5', '12/02/2026', 'XX-INCONNU', '', 'SANDJI PLUS', 'BINI Obed', 'OUI', '', '', 'OUI', ''],
        ]);

        $this->artisan('crm:importer-installations', ['fichier' => $fichier, '--simulation' => true])->assertSuccessful();
        $this->assertSame(0, Installation::count());

        $this->artisan('crm:importer-installations', ['fichier' => $fichier])->assertSuccessful();
        $this->assertSame(5, Installation::count());
        $this->assertSame($parPhoto->id_client, Installation::find('a1')->id_client);
        $this->assertSame('864107079857460', Installation::find('a1')->num_serie_pompe);
        $this->assertSame('2026-03-25', Installation::find('a1')->date_installation->toDateString());
        $this->assertSame($parPiece->id_client, Installation::find('a2')->id_client);
        $this->assertSame($parTelephone->id_client, Installation::find('a3')->id_client);
        $this->assertSame(0, Installation::find('a4')->systeme_installe);
        $this->assertNull(Installation::find('a5')->id_client); // client introuvable : gardé sans client

        // Relancer ne crée pas de doublon
        $this->artisan('crm:importer-installations', ['fichier' => $fichier])->assertSuccessful();
        $this->assertSame(5, Installation::count());

        $installes = [$parPhoto, $parPiece, $parTelephone];
        $scoring = fn (Client $c) => $c->creditScorings()->first();
        Cache::forget('table-installation-disponible');

        // Validation crédit : seuls les non installés restent
        $file = Client::query()->fileCredit('a_traiter')->pluck('id_client')->all();
        $this->assertEqualsCanonicalizing([$nonInstalle->id_client, $enAttente->id_client], $file);

        // Préparation installation : sortis des onglets d'action, présents dans « Déjà installés »
        Livewire::test(ListePreparationInstallation::class)->set('activeTab', 'pret')
            ->assertCanSeeTableRecords([$scoring($nonInstalle), $scoring($enAttente)])
            ->assertCanNotSeeTableRecords(array_map($scoring, $installes));
        Livewire::test(ListePreparationInstallation::class)->set('activeTab', 'installe')
            ->assertCanSeeTableRecords(array_map($scoring, $installes))
            ->assertCanNotSeeTableRecords([$scoring($nonInstalle)]);

        // Assignation des kits : seuls les clients prêts et pas encore installés
        Livewire::test(AssignationKits::class)
            ->assertCanSeeTableRecords([$scoring($nonInstalle), $scoring($enAttente)])
            ->assertCanNotSeeTableRecords(array_map($scoring, $installes));

        // Fiche : plus d'action en attente
        $this->get(ClientResource::getUrl('view', ['record' => $parPhoto]))
            ->assertOk()
            ->assertSee('Système installé')
            ->assertSee('25/03/2026');
        $this->assertTrue($parPhoto->estInstalle());
        $this->assertFalse($nonInstalle->estInstalle());
    }

    public function test_ecran_des_installations_installes_et_non_installes(): void
    {
        $client = $this->dossier('CI0001');
        Installation::create(['id_installation' => 'i1', 'id_client' => $client->id_client, 'systeme_installe' => 1, 'kit_recu' => 1,
            'date_installation' => '2026-03-25', 'type_produit' => 'SANDJI PLUS', 'assignee_a' => 'KOUAME Wilfried', 'num_serie_pompe' => '864107',
            'donnees_origine' => ['Le système a-t-il été installé ?' => 'OUI', 'La pompe a-t-elle été testée ?' => 'OUI',
                'Profondeur de la source d\'eau (en mètres)' => '65',
                'Photo de l\'installation' => 'https://www.appsheet.com/image/getimageurl?x=1']]);
        $echec = Installation::create(['id_installation' => 'i2', 'systeme_installe' => 0, 'cause_non_installation' => 'Annulée sur site',
            'donnees_origine' => ['Nom et prenoms du client' => 'KONE Awa']]);
        $installe = Installation::find('i1');

        $liste = fn (string $onglet) => Livewire::test(\App\Filament\Resources\Installations\Pages\ListeInstallations::class)->set('activeTab', $onglet);

        $liste('tous')->assertOk()->assertCanSeeTableRecords([$installe, $echec])
            ->assertSee('Installé')->assertSee('Non installé')->assertSee('Annulée sur site')->assertSee('KONE Awa');
        $liste('installes')->assertCanSeeTableRecords([$installe])->assertCanNotSeeTableRecords([$echec]);
        $liste('non_installes')->assertCanSeeTableRecords([$echec])->assertCanNotSeeTableRecords([$installe]);

        // Rapport détaillé : contrôles, mesures, photos
        $liste('tous')
            ->mountAction(\Filament\Actions\Testing\TestAction::make('voirRapport')->table($installe))
            ->assertMountedActionModalSee('Système installé')
            ->assertMountedActionModalSee('La pompe a-t-elle été testée ?')
            ->assertMountedActionModalSee('Profondeur de la source d')
            ->assertMountedActionModalSee('864107');
    }
}
