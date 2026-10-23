<?php

namespace Database\Seeders;

use App\Enums\EtapePipeline;
use App\Models\Client;
use App\Models\Opportunite;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Comptes de démonstration (mot de passe « password ») :
     *   admin@greeno.test, commercial1@greeno.test, commercial2@greeno.test
     */
    public function run(): void
    {
        $admin = User::factory()->create(['name' => 'Admin Greeno', 'email' => 'admin@greeno.test']);
        $commerciaux = collect([
            $admin,
            User::factory()->create(['name' => 'Camille Martin', 'email' => 'commercial1@greeno.test']),
            User::factory()->create(['name' => 'Julien Bernard', 'email' => 'commercial2@greeno.test']),
        ]);

        // Prospects sans projet encore qualifié
        Client::factory(8)->sequence(fn () => ['commercial_id' => $commerciaux->random()->id])->create();

        // Opportunités réparties sur tout le pipeline (les jalons et statuts sont calculés par le modèle)
        $repartition = [
            EtapePipeline::Prospect->value => 6,
            EtapePipeline::VisiteTechnique->value => 5,
            EtapePipeline::Devis->value => 5,
            EtapePipeline::Signe->value => 3,
            EtapePipeline::Installation->value => 2,
            EtapePipeline::MiseEnService->value => 4,
            EtapePipeline::Perdu->value => 2,
        ];

        foreach ($repartition as $etape => $nombre) {
            for ($i = 0; $i < $nombre; $i++) {
                $commercial = $commerciaux->random();
                $client = Client::factory()->create(['commercial_id' => $commercial->id]);

                $opportunite = Opportunite::factory()->create([
                    'client_id' => $client->id,
                    'commercial_id' => $commercial->id,
                ]);

                // On fait avancer l'opportunité étape par étape pour dater chaque jalon
                $cible = EtapePipeline::from($etape);
                $parcours = $cible === EtapePipeline::Perdu
                    ? [EtapePipeline::VisiteTechnique, EtapePipeline::Devis, EtapePipeline::Perdu]
                    : array_slice(EtapePipeline::ordonnees(), 1, array_search($cible, EtapePipeline::ordonnees(), true));

                foreach ($parcours as $palier) {
                    $opportunite->update([
                        'etape' => $palier,
                        'motif_perte' => $palier === EtapePipeline::Perdu
                            ? fake()->randomElement(['Prix trop élevé', 'Choix d\'un concurrent', 'Projet reporté'])
                            : null,
                    ]);
                }
            }
        }
    }
}
