<?php

namespace Tests\Feature;

use App\Filament\Clusters\Parametres\Pages\PhotosParametres;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\FormulaireChamp;
use App\Models\User;
use App\Photos\ParametresPhotos;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

/** Paramètres > Photos : nom des fichiers d'après un modèle, copie vers un dossier Google Drive. */
class PhotosTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    private const DOSSIER = '1AbCdEfGhIjKlMnOpQrStUv';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Cache::forget('parametres-photos');
        Cache::forget('google-drive-jeton');
        $this->creerReferences();
        $this->actingAs(User::factory()->create());
    }

    /** Clé de compte de service de test (clé RSA générée à la volée). */
    private function cleGoogle(): void
    {
        // Sous Windows, OpenSSL a besoin de son fichier de configuration (fourni avec PHP)
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        foreach ([getenv('OPENSSL_CONF'), dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'] as $conf) {
            if ($conf && is_file($conf)) {
                $options['config'] = $conf;
                break;
            }
        }
        $cle = openssl_pkey_new($options);
        openssl_pkey_export($cle, $privee, null, $options);
        $chemin = tempnam(sys_get_temp_dir(), 'drive').'.json';
        file_put_contents($chemin, json_encode(['client_email' => 'crm@greeno-test.iam.gserviceaccount.com', 'private_key' => $privee]));
        config(['services.google_drive.cle' => $chemin]);
    }

    private function ajouterKyc($client): void
    {
        Livewire::test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction(TestAction::make('ajouterKyc')->schemaComponent('section-kyc', schema: 'infolist'), data: [
                'type_piece_id' => 1, 'num_piece_id' => 'CI0001', 'd_nais' => '1985-01-01', 'nom_parent_proche' => 'AYA',
                'id_lien_par' => 8, 'contact_parent_1' => '0545387871',
                'photo_id_face1' => UploadedFile::fake()->image('IMG_2041.JPG'),
            ])
            ->assertHasNoActionErrors();
    }

    public function test_nom_de_fichier_d_apres_le_modele(): void
    {
        $champ = FormulaireChamp::pour('kyc')->firstWhere('cle', 'photo_id_face1');
        $client = $this->creerClient(['nom_client' => 'KONÉ', 'prenoms_client' => 'Adama Junior']);
        $date = now()->format('Y-m-d');

        $this->assertSame("kyc_photo_id_face1_{$date}_KONE-Adama-Junior_{$client->id_client}", ParametresPhotos::nomFichier('kyc', $champ, $client));
        $this->assertSame("{$date}_Recto-de-la-piece", ParametresPhotos::nomFichier('kyc', $champ, $client, modele: '{date}_{question}'));
        // Client pas encore enregistré : la variable vide disparaît
        $this->assertSame("client_photo_{$date}_DIALLO", ParametresPhotos::nomFichier('client', new FormulaireChamp(['cle' => 'photo', 'libelle' => 'Photo']), null, ['nom_client' => 'DIALLO']));

        $this->assertSame(self::DOSSIER, ParametresPhotos::idDossierDrive('https://drive.google.com/drive/folders/'.self::DOSSIER.'?usp=sharing'));
        $this->assertSame(self::DOSSIER, ParametresPhotos::idDossierDrive('https://drive.google.com/open?id='.self::DOSSIER));
        $this->assertNull(ParametresPhotos::idDossierDrive('https://exemple.com'));
    }

    public function test_la_photo_est_nommee_et_copiee_dans_drive(): void
    {
        $this->cleGoogle();
        ParametresPhotos::enregistrer(['kyc' => ['actif' => true, 'lien' => 'https://drive.google.com/drive/folders/'.self::DOSSIER, 'modele' => '{table}_{champ}_{date}_{nom_client}_{id_client}']]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'jeton-test', 'expires_in' => 3600]),
            'www.googleapis.com/upload/*' => Http::response(['id' => 'fichier-drive-1']),
        ]);

        $client = $this->creerClient(['nom_client' => 'KONE', 'prenoms_client' => 'Adama']);
        $this->ajouterKyc($client);

        $chemin = $client->fresh()->kyc->photo_id_face1;
        $this->assertSame('kyc_photo_id_face1_'.now()->format('Y-m-d').'_KONE-Adama_'.$client->id_client.'.jpg', basename($chemin));
        Storage::disk('public')->assertExists($chemin);

        Http::assertSent(fn (Request $requete) => str_contains($requete->url(), 'upload/drive/v3/files')
            && $requete->hasHeader('Authorization', 'Bearer jeton-test')
            && str_contains($requete->body(), '"parents":["'.self::DOSSIER.'"]')
            && str_contains($requete->body(), basename($chemin)));
        $this->assertTrue(ParametresPhotos::journal()[0]['ok']);
    }

    public function test_sans_dossier_drive_rien_n_est_envoye(): void
    {
        Http::fake();

        $this->ajouterKyc($this->creerClient());

        Http::assertNothingSent();
    }

    public function test_page_parametres_photos(): void
    {
        Livewire::test(PhotosParametres::class)
            ->assertOk()
            ->assertSee('KYC')
            ->assertSee('Dimensionnement')
            // Envoi activé sans lien : refusé
            ->set('data.kyc.actif', true)
            ->set('data.kyc.lien', '')
            ->call('enregistrer')
            ->assertHasErrors(['data.kyc.lien' => 'required'])
            ->set('data.kyc.lien', 'https://drive.google.com/drive/folders/'.self::DOSSIER)
            ->set('data.kyc.modele', '{champ}_{date}_{nom_client}')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertTrue(ParametresPhotos::pour('kyc')['actif']);
        $this->assertSame('{champ}_{date}_{nom_client}', ParametresPhotos::pour('kyc')['modele']);
        $this->assertFalse(ParametresPhotos::pour('eval_site')['actif']);
    }
}
