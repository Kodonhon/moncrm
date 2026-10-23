<?php

namespace App\Console\Commands;

use App\Models\CreditScoring;
use App\Models\FormulaireChamp;
use App\Models\FormulaireSection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('crm:ajuster-credit-scoring {--simulation : Afficher les changements sans les enregistrer}')]
#[Description('Formulaire credit scoring : contact repris de la prospection, questions masquées si l\'appel n\'est pas « Joint », Élevage / Agriculture séparés, pompe reprise du TDH')]
class AjusterCreditScoring extends Command
{
    private const FORMULAIRE = 'credit_scoring';

    /** Statut de l'appel = Joint (code 1). */
    private const JOINT = ['champ' => CreditScoring::CHAMP_APPEL, 'operateur' => 'egal', 'valeurs' => ['1']];

    private const AGRICULTURE = ['champ' => 'q1_cs', 'operateur' => 'egal', 'valeurs' => ['1']];

    private const ELEVAGE = ['champ' => 'q1_cs', 'operateur' => 'egal', 'valeurs' => ['4']];

    private const AGRICULTURE_OU_ELEVAGE = ['champ' => 'q1_cs', 'operateur' => 'egal', 'valeurs' => ['1', '4']];

    private array $journal = [];

    public function handle(): int
    {
        if (! FormulaireChamp::query()->where('formulaire', self::FORMULAIRE)->exists()) {
            $this->error('Le formulaire credit scoring n\'est pas installé (php artisan crm:credit-scoring).');

            return self::FAILURE;
        }

        DB::beginTransaction();

        // 1. Contact 1 = numéro saisi lors de la prospection (fiche client), non modifiable ici
        $this->champ('q3_cs', [
            'defaut' => 'client.contact1_client',
            'aide' => 'Numéro saisi lors de la prospection (fiche client). À corriger sur la fiche du client si besoin.',
        ]);

        // 2. Statut de l'appel différent de « Joint » : seules les questions d'appel et la décision restent
        foreach (['q2_cs', 'q5_cs'] as $cle) {
            $this->ajouterCondition($cle, self::JOINT);
        }
        foreach (['Activité et terrain', 'Kit et paiement', 'Eau et énergie', 'Revenus et prêts', 'Stabilité'] as $section) {
            $this->conditionSection($section, [self::JOINT]);
        }
        foreach (['q36_cs', 'q44_cs', 'q45_cs', 'q46_cs'] as $cle) {
            $this->ajouterCondition($cle, self::JOINT);
        }

        // 3. Élevage et agriculture séparés, selon « Pour quelle activité souhaitez-vous utiliser la pompe ? »
        $ancienne = FormulaireSection::query()->where('formulaire', self::FORMULAIRE)->where('nom', 'Élevage et agriculture')->first();
        $ordre = $ancienne?->ordre ?? 40;

        $this->section('Agriculture', $ordre, [self::JOINT, self::AGRICULTURE], 'Affichée si la pompe sert à l\'agriculture.');
        $this->section('Élevage', $ordre + 1, [self::JOINT, self::ELEVAGE], 'Affichée si la pompe sert à l\'élevage.');

        foreach (['q16_cs', 'q17_cs'] as $cle) {
            $this->champ($cle, ['section' => 'Agriculture']);
        }
        foreach (['q15_cs', 'q15_cs_autre', 'q18_cs', 'q25_cs'] as $cle) {
            $this->champ($cle, ['section' => 'Élevage']);
        }
        // Ancienneté dans l'activité : commune aux deux, placée sous la question de l'activité
        $ordreQ1 = FormulaireChamp::query()->where('formulaire', self::FORMULAIRE)->where('cle', 'q1_cs')->value('ordre') ?? 0;
        $this->champ('q24_cs', ['section' => 'Activité et terrain', 'ordre' => $ordreQ1 + 1]);
        $this->ajouterCondition('q24_cs', self::AGRICULTURE_OU_ELEVAGE);

        if ($ancienne && $ancienne->visible) {
            $ancienne->visible = false;
            $this->enregistrer($ancienne, 'section « Élevage et agriculture » masquée (remplacée par Agriculture et Élevage)');
        }

        // 4. Pompe proposée : reprise du produit de l'évaluation du site (TDH)
        $this->champ('q12_cs', [
            'defaut' => 'client.evalSite.code_pompe_cs',
            'aide' => 'Repris du produit de la dernière évaluation du site (TDH). À corriger dans l\'évaluation si besoin.',
        ]);

        if ($this->option('simulation')) {
            DB::rollBack();
            $this->table(['Changement'], array_map(fn ($l) => [$l], $this->journal));
            $this->info('Simulation : rien n\'a été enregistré.');

            return self::SUCCESS;
        }

        DB::commit();
        $this->table(['Changement'], array_map(fn ($l) => [$l], $this->journal));
        $this->info('Formulaire credit scoring ajusté.');

        return self::SUCCESS;
    }

    private function trouver(string $cle): ?FormulaireChamp
    {
        $champ = FormulaireChamp::query()->where('formulaire', self::FORMULAIRE)->where('cle', $cle)->first();

        if (! $champ) {
            $this->warn("Question {$cle} introuvable : ignorée.");
        }

        return $champ;
    }

    private function champ(string $cle, array $valeurs): void
    {
        if ($champ = $this->trouver($cle)) {
            $champ->fill($valeurs);
            $this->enregistrer($champ, "{$cle} : ".collect($valeurs)->map(fn ($v, $k) => "{$k} = ".(is_array($v) ? json_encode($v) : $v))->implode(', '));
        }
    }

    /** Ajoute une condition d'affichage (ou remplace celle qui porte sur la même question). */
    private function ajouterCondition(string $cle, array $condition): void
    {
        if (! $champ = $this->trouver($cle)) {
            return;
        }

        $conditions = collect($champ->conditions ?? [])
            ->reject(fn ($c) => ($c['champ'] ?? null) === $condition['champ'] && ($condition['champ'] !== 'q1_cs' || ($c['valeurs'] ?? []) === $condition['valeurs']))
            ->push($condition)
            ->values()
            ->all();

        $champ->conditions = $conditions;
        $this->enregistrer($champ, "{$cle} : affichée si ".$condition['champ'].' = '.implode(' ou ', $condition['valeurs']));
    }

    private function conditionSection(string $nom, array $conditions): void
    {
        $section = FormulaireSection::query()->where('formulaire', self::FORMULAIRE)->where('nom', $nom)->first();

        if (! $section) {
            $this->warn("Section « {$nom} » introuvable : ignorée.");

            return;
        }

        $champsConditions = collect($conditions)->pluck('champ')->all();
        $section->conditions = collect($section->conditions ?? [])
            ->reject(fn ($c) => in_array($c['champ'] ?? null, $champsConditions, true))
            ->merge($conditions)
            ->values()
            ->all();
        $this->enregistrer($section, "section « {$nom} » : affichée seulement si l'appel est « Joint »");
    }

    private function section(string $nom, int $ordre, array $conditions, string $description): void
    {
        $section = FormulaireSection::query()->firstOrNew(['formulaire' => self::FORMULAIRE, 'nom' => $nom]);
        $section->fill(['ordre' => $ordre, 'visible' => true, 'conditions' => $conditions, 'description' => $description]);
        $this->enregistrer($section, "section « {$nom} » (ordre {$ordre}) : {$description}");
    }

    private function enregistrer($modele, string $libelle): void
    {
        if ($modele->isDirty() || ! $modele->exists) {
            $modele->save();
            $this->journal[] = $libelle;
        }
    }
}
