<?php

namespace App\Console\Commands;

use App\Models\PaiementContrat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Vérifie l'accès à la base des ventes (Railway) et affiche un aperçu de la vue paiement_contrat. Lecture seule. */
class TesterBaseVentes extends Command
{
    protected $signature = 'crm:ventes-tester {--lignes=5 : nombre de lignes d\'aperçu}';

    protected $description = 'Teste la connexion à la base des ventes et affiche un aperçu des paiements de contrats';

    public function handle(): int
    {
        if (blank(config('database.connections.ventes.password'))) {
            $this->error('VENTES_DB_PASSWORD est vide dans .env.');

            return self::FAILURE;
        }

        try {
            $total = PaiementContrat::query()->count();
            $avecContrat = PaiementContrat::query()->whereNotNull('num_contrat_upya')->distinct()->count('num_contrat_upya');
            $maj = PaiementContrat::query()->max('date_maj');
            $apercu = PaiementContrat::query()->orderByDesc('dernier_paiement')->limit((int) $this->option('lignes'))->get();
        } catch (Throwable $e) {
            $this->error('Connexion impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Connexion OK — {$total} lignes, {$avecContrat} contrats UPYA, dernière mise à jour : ".($maj ?? '—'));

        $this->table(
            ['Contrat UPYA', 'Entité', 'Statut AMT', 'Dépôt', 'Total payé', 'Premier paiement', 'Dernier paiement'],
            $apercu->map(fn (PaiementContrat $p) => [
                $p->num_contrat_upya, $p->entite, $p->statut_amt, $p->mt_deposit, $p->total_paye,
                $p->premier_paiement?->format('d/m/Y'), $p->dernier_paiement?->format('d/m/Y'),
            ])->all(),
        );

        // Contrats UPYA connus du CRM (scoring, préparation, installation) et retrouvés dans la base des ventes.
        // Le KYC ne sert pas : sa « Référence UPYA » est la référence client (CI00…), pas le n° de contrat.
        // Comparaison sans les zéros de tête (« 035164558 » = « 35164558 »).
        $normaliser = fn ($n) => ltrim(preg_replace('/\D/', '', (string) $n), '0');
        $ventes = PaiementContrat::query()->pluck('num_contrat_upya')->map($normaliser)->flip();
        $contratsCrm = collect(['credit_scoring', 'prep_inst', 'installation'])
            ->filter(fn ($table) => \Illuminate\Support\Facades\Schema::hasTable($table))
            ->flatMap(fn ($table) => DB::table($table)->whereNotNull('num_contrat_upya')->pluck('num_contrat_upya'))
            ->map($normaliser)->filter()->unique();
        $trouves = $contratsCrm->filter(fn ($n) => isset($ventes[$n]))->count();
        $this->line("Contrats UPYA connus du CRM : {$contratsCrm->count()}, retrouvés dans la base des ventes : {$trouves}");

        return self::SUCCESS;
    }
}
