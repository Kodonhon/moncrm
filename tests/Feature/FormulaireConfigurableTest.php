<?php

namespace Tests\Feature;

use App\Console\Commands\ImporterDonnees;
use App\Filament\Clusters\Parametres\Resources\Pages\GererFormulaireClient;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Formulaires\ListesReference;
use App\Models\Client;
use App\Models\FormulaireChamp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\DonneesReference;
use Tests\TestCase;

class FormulaireConfigurableTest extends TestCase
{
    use DonneesReference, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerReferences();
        $this->actingAs(User::factory()->create());
    }

    private function champ(string $cle): FormulaireChamp
    {
        return FormulaireChamp::where('formulaire', 'client')->where('cle', $cle)->firstOrFail();
    }

    public function test_une_question_masquee_disparait_du_formulaire(): void
    {
        Livewire::test(CreateClient::class)->assertFormFieldExists('profession_client');

        $this->champ('profession_client')->update(['visible' => false]);

        Livewire::test(CreateClient::class)->assertFormFieldDoesNotExist('profession_client');
    }

    public function test_une_question_masquee_ne_vide_pas_les_donnees_existantes(): void
    {
        $client = $this->creerClient(['profession_client' => 'Planteur']);
        $this->champ('profession_client')->update(['visible' => false]);

        Livewire::test(EditClient::class, ['record' => $client->getKey()])
            ->fillForm(['nom_client' => 'NOUVEAU NOM'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Planteur', $client->fresh()->profession_client);
        $this->assertSame('NOUVEAU NOM', $client->fresh()->nom_client);
    }

    public function test_condition_d_affichage(): void
    {
        // Par défaut : « Horizon d'achat » seulement si « Système souhaité » est renseigné
        Livewire::test(CreateClient::class)
            ->assertFormFieldHidden('horizon_achat_client')
            ->fillForm(['systeme_souhaite_client' => 'TEVIA ACCES'])
            ->assertFormFieldVisible('horizon_achat_client');

        // Condition « est » sur une valeur précise
        $this->champ('profession_client')->update([
            'conditions' => [['champ' => 'genre_client', 'operateur' => 'egal', 'valeurs' => ['0']]],
        ]);

        Livewire::test(CreateClient::class)
            ->fillForm(['genre_client' => 1])
            ->assertFormFieldHidden('profession_client')
            ->fillForm(['genre_client' => 0])
            ->assertFormFieldVisible('profession_client');
    }

    public function test_liste_filtree_par_une_autre_question(): void
    {
        $composant = Livewire::test(CreateClient::class);

        // Sans ville : toutes les localités
        $this->assertCount(3, $this->optionsDe($composant, 'id_localite_commune'));

        // Ville GAGNOA (13) : seulement GAGNOA et GUIBEROUA
        $composant->fillForm(['ville_filtre' => 13]);
        $this->assertEqualsCanonicalizing([2, 3], array_keys($this->optionsDe($composant, 'id_localite_commune')));

        // Source Marketing (2) : seulement les canaux marketing
        $composant->fillForm(['id_src_client' => 2]);
        $this->assertSame([2], array_keys($this->optionsDe($composant, 'id_canal_vente')));
    }

    public function test_question_ajoutee_avec_liste_en_cascade(): void
    {
        FormulaireChamp::create([
            'formulaire' => 'client', 'cle' => 'q_logement', 'libelle' => 'Type de logement', 'type' => 'liste',
            'stockage' => 'perso', 'section' => 'Projet', 'ordre' => 500,
            'source_liste' => ListesReference::PERSONNALISEE,
            'options' => [['valeur' => 'Maison', 'libelle' => 'Maison', 'parent' => null], ['valeur' => 'Boutique', 'libelle' => 'Boutique', 'parent' => null]],
        ]);
        FormulaireChamp::create([
            'formulaire' => 'client', 'cle' => 'q_toiture', 'libelle' => 'Toiture', 'type' => 'liste',
            'stockage' => 'perso', 'section' => 'Projet', 'ordre' => 510,
            'source_liste' => ListesReference::PERSONNALISEE, 'liste_parent' => 'q_logement',
            'options' => [
                ['valeur' => 'Tôle', 'libelle' => 'Tôle', 'parent' => 'Maison'],
                ['valeur' => 'Dalle', 'libelle' => 'Dalle', 'parent' => 'Maison'],
                ['valeur' => 'Auvent', 'libelle' => 'Auvent', 'parent' => 'Boutique'],
            ],
        ]);

        $composant = Livewire::test(CreateClient::class)
            ->fillForm(['donnees_formulaire.q_logement' => 'Boutique']);

        $this->assertSame(['Auvent'], array_keys($this->optionsDe($composant, 'donnees_formulaire.q_toiture')));

        $composant->fillForm([
            'nom_client' => 'YAO', 'contact1_client' => '0505050505', 'ville_filtre' => 13, 'id_localite_commune' => 2,
            'id_canal_vente' => 1, 'donnees_formulaire.q_toiture' => 'Auvent',
        ])->call('create')->assertHasNoFormErrors();

        $this->assertSame(
            ['q_logement' => 'Boutique', 'q_toiture' => 'Auvent'],
            Client::where('nom_client', 'YAO')->first()->donnees_formulaire,
        );
    }

    public function test_l_agent_qui_enregistre_le_client(): void
    {
        $composant = Livewire::test(CreateClient::class)->assertFormFieldExists('id_agent');

        // Les agents désactivés ne sont pas proposés
        $this->assertSame([1 => 'Koffi Yao'], $this->optionsDe($composant, 'id_agent'));

        $composant->fillForm([
            'nom_client' => 'BAMBA', 'contact1_client' => '0707070707', 'ville_filtre' => 13, 'id_localite_commune' => 2,
            'id_canal_vente' => 1, 'id_agent' => 1,
        ])->call('create')->assertHasNoFormErrors();

        $client = Client::where('nom_client', 'BAMBA')->firstOrFail();
        $this->assertSame('Koffi Yao', $client->agent->nom_agent);

        $this->get(\App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $client]))->assertSee('Koffi Yao');
        $this->get('/admin/parametres/agents')->assertOk()->assertSee('Koffi Yao');
    }

    public function test_les_tables_de_reference_sont_servies_depuis_le_cache(): void
    {
        $client = $this->creerClient(['id_agent' => 1]);
        $this->get(\App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $client]))->assertOk(); // remplit le cache
        Livewire::test(\App\Filament\Resources\Clients\Pages\ListClients::class);

        \App\Support\CacheReferences::viderMemoire();
        $requetes = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$requetes) { $requetes[] = $q->sql; });

        Livewire::test(\App\Filament\Resources\Clients\Pages\ListClients::class)->assertCanSeeTableRecords([$client]);

        $tablesDeReference = ['canal_vente', 'localite_commune', 'ville', 'site_shop', 'src_client', 'genre', 'agent', 'entite'];
        $lues = collect($requetes)->filter(fn ($sql) => collect($tablesDeReference)->contains(fn ($t) => preg_match('/from "'.$t.'"/', $sql)));
        $this->assertCount(0, $lues, 'Requêtes inattendues : '.$lues->implode(' | '));

        // Une modification dans Paramètres vide le cache : le nouveau libellé apparaît tout de suite
        \App\Models\Agent::find(1)->update(['nom_agent' => 'Koffi Yao Junior']);
        $this->get(\App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $client]))->assertSee('Koffi Yao Junior');
    }

    public function test_reordonner_les_questions_change_le_formulaire(): void
    {
        $prenoms = $this->champ('prenoms_client');
        $nom = $this->champ('nom_client');

        Livewire::test(GererFormulaireClient::class)
            ->call('reorderTable', [(string) $prenoms->id, (string) $nom->id]);

        $ordre = FormulaireChamp::pour('client')->pluck('cle')->take(2)->all();
        $this->assertSame(['prenoms_client', 'nom_client'], $ordre);
    }

    public function test_les_villes_sont_nommees_d_apres_leur_chef_lieu(): void
    {
        $methode = new \ReflectionMethod(ImporterDonnees::class, 'construireVilles');
        $commande = new ImporterDonnees;
        $commande->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput));

        $villes = $methode->invoke($commande, [
            ['id_ville' => '17', 'lib_localite' => 'BEDIALA'],
            ['id_ville' => '17', 'lib_localite' => 'DALOA'],
            ['id_ville' => '0', 'lib_localite' => 'Error'],
        ]);

        $this->assertSame([0 => 'Inconnue', 17 => 'DALOA'], collect($villes)->pluck('lib_ville', 'id_ville')->all());
    }

    private function optionsDe($composant, string $chemin): array
    {
        $champ = FormulaireChamp::pour('client')->first(fn ($c) => $c->cheminEtat() === $chemin);
        $options = null;

        $composant->assertFormFieldExists($chemin, function ($field) use (&$options) {
            $options = $field->getOptions();

            return true;
        });

        $this->assertNotNull($champ);

        return $options;
    }
}
