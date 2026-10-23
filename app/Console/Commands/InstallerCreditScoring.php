<?php

namespace App\Console\Commands;

use App\Formulaires\ChampsParDefaut;
use App\Models\Agent;
use App\Models\CreditScoring;
use App\Models\FormulaireChamp;
use App\Models\Kyc;
use App\Support\CacheReferences;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('crm:credit-scoring
    {dossier? : Dossier contenant credit_scoring.csv (par défaut : Téléchargements\Table CRM)}
    {--sans-historique : Installer seulement les questions du formulaire, sans importer le fichier}
    {--remplacer : Vider la table credit_scoring avant l\'import}
    {--mettre-a-jour-questions : Remplacer les questions et sections du formulaire par celles par défaut (libellés AppSheet)}
    {--recalculer-validations-kyc : Recalculer les KYC validés d\'après l\'historique (statut du CDS 2 = Validé)}')]
#[Description('Installe le formulaire de credit scoring et importe l\'historique (après database/sql/credit_scoring.sql)')]
class InstallerCreditScoring extends Command
{
    /** Colonnes du fichier d'origine reprises telles quelles. */
    private const COLONNES = [
        'id_cs', 'id_activite', 'num_cni', 'id_src_eau', 'id_periodicite', 'id_prospect', 'id_agent', 'date_cs', 'heure_cs',
        'q1_cs', 'q2_cs', 'q3_cs', 'q4_cs', 'q5_cs', 'q6_cs', 'q7_cs', 'q8_cs', 'q9_cs', 'q10_cs', 'q11_cs', 'q12_cs', 'q13_cs',
        'q14_cs', 'q15_cs', 'q15_cs_autre', 'q16_cs', 'q17_cs', 'q18_cs', 'q19_cs', 'q20_cs', 'q21_cs', 'q22_cs', 'q23_cs',
        'q24_cs', 'q25_cs', 'q26_cs', 'q27_cs', 'q28_cs', 'q28_cs_autre', 'q29_cs', 'q30_cs', 'q31_cs', 'q32_cs', 'q33_cs',
        'q34_cs', 'q35_cs', 'q36_cs', 'q37_cs', 'q38_cs', 'q39_cs', 'q40_cs', 'q41_cs', 'q42_cs', 'q43_cs', 'q44_cs', 'q45_cs',
        'q46_cs', 'q47_cs', 'id_prospect_appsheet', 'visibilite_cs', 'last_modified_by', 'num_contrat_upya',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('credit_scoring') || ! Schema::hasColumn('kyc', 'statut_validation_kyc') || ! Schema::hasColumn('agent', 'role_agent')) {
            $this->error('Exécutez d\'abord le script database/sql/credit_scoring.sql sur la base.');

            return self::FAILURE;
        }

        if ($this->option('mettre-a-jour-questions')) {
            $this->mettreAJourQuestions();
        } else {
            $this->installerQuestions();
        }

        if ($this->option('recalculer-validations-kyc')) {
            $this->recalculerValidationsKyc();
        } elseif (! $this->option('sans-historique')) {
            $resultat = $this->importerHistorique();
            if ($resultat !== self::SUCCESS) {
                return $resultat;
            }
        }

        Cache::forget('table-credit-scoring-disponible');
        CacheReferences::oublier(Agent::class);
        $this->info('Validation crédit prête.');

        return self::SUCCESS;
    }

    /** Questions et sections par défaut du formulaire (seulement si le formulaire n'existe pas encore). */
    private function installerQuestions(): void
    {
        if (FormulaireChamp::query()->where('formulaire', 'credit_scoring')->exists()) {
            $this->line('  Formulaire credit scoring : déjà installé (modifiable dans Paramètres).');

            return;
        }

        $champs = ChampsParDefaut::creditScoring();

        DB::transaction(function () use ($champs) {
            DB::table('formulaire_champ')->insert(ChampsParDefaut::pourInsertion($champs));
            DB::table('formulaire_section')->insert(array_map(fn (array $section) => [
                'formulaire' => 'credit_scoring',
                'nom' => $section['nom'],
                'ordre' => $section['ordre'],
                'visible' => true,
                'description' => $section['description'],
                'conditions' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ], ChampsParDefaut::sectionsCreditScoring()));
        });

        Cache::forget(FormulaireChamp::cleCache('credit_scoring'));
        Cache::forget(\App\Models\FormulaireSection::cleCache('credit_scoring'));
        $this->line('  Formulaire credit scoring : '.count($champs).' questions installées.');
    }

    /**
     * Remplace les questions standard du formulaire (libellés, types, choix, sections, ordre) par celles par défaut.
     * Les questions ajoutées à la main dans Paramètres (non standard) sont conservées.
     */
    private function mettreAJourQuestions(): void
    {
        $champs = collect(ChampsParDefaut::pourInsertion(ChampsParDefaut::creditScoring()));

        DB::transaction(function () use ($champs) {
            foreach ($champs as $champ) {
                $existe = DB::table('formulaire_champ')->where('formulaire', 'credit_scoring')->where('cle', $champ['cle'])->exists();
                $existe
                    ? DB::table('formulaire_champ')->where('formulaire', 'credit_scoring')->where('cle', $champ['cle'])
                        ->update(array_diff_key($champ, ['created_at' => true, 'formulaire' => true, 'cle' => true]))
                    : DB::table('formulaire_champ')->insert($champ);
            }

            // Anciennes questions standard qui n'existent plus dans le formulaire par défaut
            DB::table('formulaire_champ')->where('formulaire', 'credit_scoring')->where('standard', true)
                ->whereNotIn('cle', $champs->pluck('cle'))->delete();

            // Sections : celles par défaut (ordre, description), sans les sections devenues vides
            foreach (ChampsParDefaut::sectionsCreditScoring() as $section) {
                DB::table('formulaire_section')->updateOrInsert(
                    ['formulaire' => 'credit_scoring', 'nom' => $section['nom']],
                    ['ordre' => $section['ordre'], 'visible' => true, 'description' => $section['description'], 'conditions' => null, 'updated_at' => now(), 'created_at' => now()],
                );
            }
            DB::table('formulaire_section')->where('formulaire', 'credit_scoring')
                ->whereNotIn('nom', DB::table('formulaire_champ')->where('formulaire', 'credit_scoring')->distinct()->pluck('section'))
                ->delete();
        });

        Cache::forget(FormulaireChamp::cleCache('credit_scoring'));
        Cache::forget(\App\Models\FormulaireSection::cleCache('credit_scoring'));
        $this->line('  Formulaire credit scoring : '.$champs->count().' questions mises à jour.');
    }

    /**
     * Les validations de KYC reprises de l'historique suivent le « Statut du CDS 2 » (Validé) :
     * on retire celles posées par l'import sur un autre critère, puis on valide d'après le bon statut.
     * Une validation faite dans le CRM (sans scoring correspondant ce jour-là par cet agent) n'est pas touchée.
     */
    private function recalculerValidationsKyc(): void
    {
        $avant = DB::table('kyc')->where('statut_validation_kyc', Kyc::VALIDATION_VALIDE)->count();
        $jour = DB::getDriverName() === 'pgsql' ? 'cast(kyc.date_validation_kyc as date)' : 'date(kyc.date_validation_kyc)';

        DB::transaction(function () use ($jour) {
            // Validations posées par l'import : même client, même agent, même jour qu'un scoring de l'historique
            DB::table('kyc')
                ->where('statut_validation_kyc', Kyc::VALIDATION_VALIDE)
                ->whereExists(fn ($q) => $q->from('credit_scoring')
                    ->whereColumn('credit_scoring.id_client', 'kyc.id_client')
                    ->whereColumn('credit_scoring.id_agent', 'kyc.id_agent_validation_kyc')
                    ->whereRaw("credit_scoring.date_cs = {$jour}"))
                ->update([
                    'statut_validation_kyc' => Kyc::VALIDATION_A_VALIDER,
                    'date_validation_kyc' => null,
                    'id_agent_validation_kyc' => null,
                ]);

            $this->validerKycHistoriques();
        });
        $apres = DB::table('kyc')->where('statut_validation_kyc', Kyc::VALIDATION_VALIDE)->count();

        $this->line("  KYC validés : {$avant} avant, {$apres} après recalcul d'après le statut du CDS 2 « Validé ».");
    }

    private function importerHistorique(): int
    {
        $dossier = rtrim($this->argument('dossier') ?? getenv('USERPROFILE').'\\Downloads\\Table CRM', '\\/');
        $chemin = $dossier.DIRECTORY_SEPARATOR.'credit_scoring.csv';

        if (! is_file($chemin)) {
            $this->error("Fichier introuvable : {$chemin}");

            return self::FAILURE;
        }

        if (DB::table('credit_scoring')->exists() && ! $this->option('remplacer')) {
            $this->warn('  Historique : la table credit_scoring contient déjà des lignes (relancez avec --remplacer pour réimporter).');

            return self::SUCCESS;
        }

        $lignes = $this->lire($chemin);
        $clients = array_flip(DB::table('client')->pluck('id_client')->all());
        $kycs = DB::table('kyc')->where('visibilite_kyc', 1)->pluck('id_kyc', 'id_client')->all();

        $sansClient = 0;
        $lignes = array_map(function (array $ligne) use ($clients, $kycs, &$sansClient) {
            $idClient = isset($clients[$ligne['id_prospect'] ?? '']) ? $ligne['id_prospect'] : null;
            $sansClient += $idClient === null ? 1 : 0;

            return array_intersect_key($ligne, array_flip(self::COLONNES)) + [
                'id_client' => $idClient,
                'id_kyc' => $idClient ? ($kycs[$idClient] ?? null) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $lignes);

        DB::transaction(function () use ($lignes) {
            if ($this->option('remplacer')) {
                DB::table('credit_scoring')->delete();
            }

            $this->preparerAgents(collect($lignes)->pluck('id_agent')->filter()->unique()->values()->all());

            foreach (array_chunk($lignes, 500) as $paquet) {
                DB::table('credit_scoring')->insert($paquet);
            }
        });

        $valides = $this->validerKycHistoriques();

        $this->line(sprintf('  Historique importé : %s scorings (%s sans client retrouvé, gardés pour l\'historique).',
            number_format(count($lignes), 0, ',', ' '), number_format($sansClient, 0, ',', ' ')));
        $this->line(sprintf('  KYC marqués « validés » d\'après l\'historique (commande validée) : %s.', number_format($valides, 0, ',', ' ')));

        return self::SUCCESS;
    }

    /**
     * Les agents de l'historique deviennent des agents crédit. Ceux qui n'existent pas dans le CRM
     * sont créés sous un nom provisoire, à renommer dans Paramètres > Agents.
     */
    private function preparerAgents(array $identifiants): void
    {
        $existants = DB::table('agent')->whereIn('id_agent', $identifiants)->pluck('id_agent')->all();

        DB::table('agent')->whereIn('id_agent', $existants)->update(['role_agent' => 'credit']);

        $nouveaux = array_values(array_diff($identifiants, $existants));
        if ($nouveaux) {
            DB::table('agent')->insert(array_map(fn ($id) => [
                'id_agent' => (int) $id,
                'nom_agent' => "Agent crédit n° {$id} (historique)",
                'visibilite_agent' => 1,
                'role_agent' => 'credit',
            ], $nouveaux));

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("select setval(pg_get_serial_sequence('agent', 'id_agent'), (select coalesce(max(id_agent), 0) + 1 from agent), false)");
            }

            $this->warn('  Agents créés depuis l\'historique (à renommer dans Paramètres > Agents) : n° '.implode(', ', $nouveaux));
        }
    }

    /** KYC actifs des clients dont un scoring de l'historique a validé la commande. */
    private function validerKycHistoriques(): int
    {
        $decision = CreditScoring::CHAMP_DECISION;
        $validee = "'".CreditScoring::DECISION_VALIDEE."'";
        // Dernière validation du client : sa date et son agent
        $derniere = fn (string $colonne) => DB::raw("(select cs.{$colonne} from credit_scoring cs
            where cs.id_client = kyc.id_client and cs.{$decision} = {$validee}
            order by cs.date_cs desc, cs.heure_cs desc limit 1)");

        return DB::table('kyc')
            ->where('visibilite_kyc', 1)
            ->where('statut_validation_kyc', Kyc::VALIDATION_A_VALIDER)
            ->whereIn('id_client', fn ($q) => $q->select('id_client')->from('credit_scoring')
                ->whereNotNull('id_client')->where($decision, CreditScoring::DECISION_VALIDEE))
            ->update([
                'statut_validation_kyc' => Kyc::VALIDATION_VALIDE,
                'date_validation_kyc' => $derniere('date_cs'),
                'id_agent_validation_kyc' => $derniere('id_agent'),
            ]);
    }

    /** CSV d'export (séparateur virgule, « NULL » pour les valeurs vides). */
    private function lire(string $chemin): array
    {
        $flux = fopen($chemin, 'r');
        $entetes = array_map(fn ($entete) => trim($entete, "\xEF\xBB\xBF \""), fgetcsv($flux, escape: ''));
        $lignes = [];

        while (($valeurs = fgetcsv($flux, escape: '')) !== false) {
            if ($valeurs === [null] || count($valeurs) !== count($entetes)) {
                continue;
            }
            $lignes[] = array_combine($entetes, array_map(
                fn ($valeur) => ($valeur === 'NULL' || $valeur === '') ? null : $valeur,
                $valeurs,
            ));
        }

        fclose($flux);

        return $lignes;
    }
}
