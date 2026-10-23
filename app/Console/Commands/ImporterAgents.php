<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Support\CacheReferences;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('crm:importer-agents
    {fichier=storage/app/imports/agents.csv : Export CSV de la table agent d\'origine}
    {--decaler : Déplacer vers de nouveaux numéros les agents déjà présents dont le numéro est repris par le fichier}
    {--simulation : Afficher ce qui serait fait, sans rien écrire}')]
#[Description('Importe la liste des agents (fichier RH d\'origine) dans la table agent, en conservant leurs numéros')]
class ImporterAgents extends Command
{
    private const COLONNES = [
        'id_agent', 'id_fonction_agent', 'id_type_agent', 'id_zone_agent', 'id_dpt', 'id_int', 'id_entite', 'sup_agent_id',
        'nom_agent', 'prenoms_agent', 'genre_agent', 'date_entree_agent', 'date_elearning_agent', 'date_sortie_agent',
        'photo_agent', 'email_agent', 'visibilite_agent',
    ];

    public function handle(): int
    {
        if (! Schema::hasColumn('agent', 'nom_complet_agent')) {
            $this->error('Exécutez d\'abord le script database/sql/agent_complement.sql sur la base.');

            return self::FAILURE;
        }

        $fichier = base_path($this->argument('fichier'));
        if (! is_file($fichier)) {
            $fichier = $this->argument('fichier');
        }
        if (! is_file($fichier)) {
            $this->error("Fichier introuvable : {$this->argument('fichier')}");

            return self::FAILURE;
        }

        $lignes = $this->lire($fichier);
        $this->info(count($lignes).' agents dans le fichier.');

        // Agents déjà en base dont le numéro est repris par le fichier pour une autre personne
        $existants = DB::table('agent')->get(['id_agent', 'nom_agent', 'contact_agent'])->keyBy('id_agent');
        // (les agents provisoires « Agent crédit n° … (historique) », créés par crm:credit-scoring avec le numéro
        //  d'origine, ne sont pas des conflits : ils reçoivent simplement leur vrai nom, et gardent leur rôle)
        $provisoire = fn ($agent) => str_ends_with((string) $agent->nom_agent, '(historique)');
        $conflits = collect($lignes)
            ->filter(fn ($l) => isset($existants[$l['id_agent']])
                && ! $provisoire($existants[$l['id_agent']])
                && mb_strtolower(trim($existants[$l['id_agent']]->nom_agent)) !== mb_strtolower(trim($l['nom_agent'])))
            ->map(fn ($l) => [$l['id_agent'], $existants[$l['id_agent']]->nom_agent, trim($l['nom_agent'].' '.$l['prenoms_agent'])]);

        if ($conflits->isNotEmpty()) {
            $this->warn($conflits->count().' numéro(s) déjà utilisé(s) par un autre agent :');
            $this->table(['N°', 'Agent actuel', 'Agent du fichier'], $conflits->take(20)->all());

            if (! $this->option('decaler')) {
                $this->error('Rien n\'a été importé. Relancez avec --decaler pour déplacer ces agents vers de nouveaux numéros (leurs clients, KYC… suivent).');

                return self::FAILURE;
            }
        }

        if ($this->option('simulation')) {
            $this->info('Simulation : aucune modification.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($lignes, $conflits) {
            if ($conflits->isNotEmpty()) {
                $this->decaler($conflits->pluck(0)->all(), max(array_column($lignes, 'id_agent')));
            }

            $sansContact = array_map(fn ($l) => array_diff_key($l, ['num_phone_agent' => true]), $lignes);
            foreach (array_chunk($sansContact, 300) as $paquet) {
                DB::table('agent')->upsert($paquet, ['id_agent'], array_values(array_diff(self::COLONNES, ['id_agent'])));
            }

            // Téléphone : seulement s'il est renseigné dans le fichier (on ne remplace pas un numéro saisi dans le CRM)
            foreach ($lignes as $l) {
                if (filled($l['num_phone_agent'])) {
                    DB::table('agent')->where('id_agent', $l['id_agent'])->update(['contact_agent' => mb_substr($l['num_phone_agent'], 0, 20)]);
                }
            }

            // Les nouveaux agents prennent un numéro après le plus grand numéro importé
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT setval(pg_get_serial_sequence('agent', 'id_agent'), (SELECT max(id_agent) FROM agent))");
            }
        });

        CacheReferences::oublier(Agent::class);
        $this->info('Import terminé : '.DB::table('agent')->count().' agents en base, dont '.DB::table('agent')->where('visibilite_agent', 1)->count().' actifs.');

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    private function lire(string $fichier): array
    {
        $flux = fopen($fichier, 'r');
        $entete = array_map(fn ($c) => trim($c, "\u{FEFF} \""), fgetcsv($flux, escape: ''));
        $lignes = [];

        while (($valeurs = fgetcsv($flux, escape: '')) !== false) {
            if (count($valeurs) !== count($entete)) {
                continue;
            }

            $l = array_map(fn ($v) => ($v === 'NULL' || $v === '') ? null : $v, array_combine($entete, $valeurs));

            $ligne = [];
            foreach (self::COLONNES as $colonne) {
                $ligne[$colonne] = $l[$colonne] ?? null;
            }
            $ligne['num_phone_agent'] = $l['num_phone_agent'] ?? null;

            // Nettoyage : 0 = « aucun » pour l'entité et le supérieur, visibilité vide = inactif
            foreach (['id_entite', 'sup_agent_id'] as $colonne) {
                if ((int) $ligne[$colonne] === 0) {
                    $ligne[$colonne] = null;
                }
            }
            $ligne['visibilite_agent'] = (int) ($ligne['visibilite_agent'] ?? 0);
            $ligne['nom_agent'] = trim((string) $ligne['nom_agent']) ?: '(sans nom)';
            $ligne['id_agent'] = (int) $ligne['id_agent'];

            $lignes[] = $ligne;
        }

        fclose($flux);

        return $lignes;
    }

    /**
     * Déplace des agents vers des numéros libres, en mettant à jour toutes les tables qui les référencent
     * (clés étrangères vers agent : client, kyc, contrat…).
     */
    private function decaler(array $numeros, int $maxFichier): void
    {
        $references = DB::select("SELECT c.conrelid::regclass::text AS table_ref, a.attname AS colonne
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND c.confrelid = 'agent'::regclass AND c.conrelid <> 'agent'::regclass");

        $suivant = max($maxFichier, (int) DB::table('agent')->max('id_agent')) + 1;
        $colonnes = collect(Schema::getColumnListing('agent'))->reject(fn ($c) => $c === 'nom_complet_agent')->values()->all();

        foreach ($numeros as $ancien) {
            $nouveau = $suivant++;
            $agent = (array) DB::table('agent')->where('id_agent', $ancien)->first($colonnes);
            DB::table('agent')->insert(['id_agent' => $nouveau] + $agent);

            foreach ($references as $ref) {
                DB::table($ref->table_ref)->where($ref->colonne, $ancien)->update([$ref->colonne => $nouveau]);
            }
            DB::table('agent')->where('sup_agent_id', $ancien)->update(['sup_agent_id' => $nouveau]);
            DB::table('agent')->where('id_agent', $ancien)->delete();

            $this->line("  agent {$ancien} → {$nouveau}");
        }
    }
}
