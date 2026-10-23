<?php

namespace App\Contrats;

use App\Models\Client;
use App\Models\Contrat;
use App\Models\EvalSite;
use App\Models\Kyc;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Création d'un contrat : un TDH validé + le KYC valable du même client, un seul contrat par TDH.
 *
 * Les mêmes règles sont appliquées deux fois : ici (messages clairs dans le formulaire) et dans la base
 * par le trigger contrat_verifier_regles (database/sql/contrat_regles.sql), qui fait foi.
 */
class CreerContrat
{
    /**
     * @param  array{id_eval: string, id_kyc?: string|null, id_prod?: int|null, id_type_vente?: int|null, montant?: mixed, date_contrat?: mixed, id_agent?: int|null}  $donnees
     *
     * @throws ValidationException
     */
    public function __invoke(Client $client, array $donnees): Contrat
    {
        $this->verifier($client, $donnees);

        try {
            return DB::transaction(function () use ($client, $donnees) {
                // Verrou sur le TDH : deux personnes ne peuvent pas créer deux contrats sur le même TDH en même temps
                EvalSite::query()->whereKey($donnees['id_eval'])->lockForUpdate()->first();
                $this->verifier($client, $donnees);

                return $client->contrats()->create(array_filter([
                    'num_contrat_upya' => Contrat::upyaDisponible() ? ($donnees['num_contrat_upya'] ?? null) : null,
                ]) + [
                    'id_eval' => $donnees['id_eval'],
                    'id_kyc' => $donnees['id_kyc'] ?? $client->kyc?->id_kyc,
                    'id_prod' => $donnees['id_prod'] ?? null,
                    'id_type_vente' => $donnees['id_type_vente'] ?? null,
                    'montant' => $donnees['montant'] ?? null,
                    'date_contrat' => $donnees['date_contrat'] ?? now()->toDateString(),
                    'id_agent' => $donnees['id_agent'] ?? null,
                ]);
            });
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['id_eval' => self::messageBase($e)]);
        }
    }

    /** Règles métier, vérifiées avant d'écrire (et rejouées dans la transaction). */
    public function verifier(Client $client, array $donnees): void
    {
        $evaluation = filled($donnees['id_eval'] ?? null) ? EvalSite::find($donnees['id_eval']) : null;

        if (! $evaluation) {
            self::refuser('id_eval', 'Choisissez le dimensionnement du contrat.');
        }
        if ($evaluation->id_client !== $client->id_client) {
            self::refuser('id_eval', 'Ce dimensionnement appartient à un autre client.');
        }
        if (! $evaluation->estValidee()) {
            self::refuser('id_eval', 'Ce dimensionnement n\'est pas validé : validez-le avant de créer le contrat.');
        }
        if ($autre = Contrat::query()->where('id_eval', $evaluation->id_eval_site)->value('num_contrat')) {
            self::refuser('id_eval', "Ce dimensionnement a déjà donné le contrat {$autre}. Un dimensionnement ne donne qu'un seul contrat.");
        }

        $kyc = filled($donnees['id_kyc'] ?? null) ? Kyc::find($donnees['id_kyc']) : $client->kyc;

        if (! $kyc) {
            self::refuser('id_kyc', 'Ce client n\'a pas de KYC : faites le KYC avant de créer le contrat.');
        }
        if ($kyc->id_client !== $client->id_client) {
            self::refuser('id_kyc', 'Ce KYC appartient à un autre client.');
        }
        if (! $kyc->estValable()) {
            self::refuser('id_kyc', (int) $kyc->visibilite_kyc !== 1
                ? 'Ce KYC n\'est pas actif : il ne peut pas servir pour un contrat.'
                : $kyc->etatPiece()['libelle'].' : mettez à jour le KYC avant de créer le contrat.');
        }

        // Un n° de contrat UPYA ne sert qu'à un seul contrat du CRM
        if (Contrat::upyaDisponible() && filled($upya = $donnees['num_contrat_upya'] ?? null)
            && ($autre = Contrat::withoutGlobalScopes()->where('num_contrat_upya', $upya)->value('num_contrat'))) {
            self::refuser('num_contrat_upya', "Le n° de contrat UPYA {$upya} est déjà utilisé par le contrat {$autre}.");
        }
    }

    /** Message à montrer à l'utilisateur quand la base refuse l'enregistrement (trigger, unicité). */
    public static function messageBase(QueryException $e): string
    {
        $erreur = $e->errorInfo[2] ?? $e->getMessage();

        // Trigger (RAISE EXCEPTION … ERRCODE P0001) : le message est déjà en français
        if (($e->errorInfo[0] ?? null) === 'P0001' && preg_match('/ERROR:\s*(.+?)(?:\n|CONTEXT:|$)/s', $erreur, $m)) {
            return trim($m[1]);
        }

        // Unicité (23505) : contrat déjà créé sur ce TDH
        if (($e->errorInfo[0] ?? null) === '23505' || str_contains($erreur, 'UNIQUE')) {
            return 'Ce dimensionnement a déjà donné un contrat. Un dimensionnement ne donne qu\'un seul contrat.';
        }

        report($e);

        return 'Le contrat n\'a pas pu être enregistré. Réessayez ou contactez l\'administrateur.';
    }

    private static function refuser(string $champ, string $message): never
    {
        throw ValidationException::withMessages([$champ => $message]);
    }
}
