<?php

namespace App\Models\Concerns;

use App\Models\FormulaireChamp;
use App\Models\JournalClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Inscrit dans le journal du client chaque création, modification et suppression du modèle.
 * Seules les actions faites dans le CRM par un utilisateur connecté sont journalisées :
 * les imports en ligne de commande (des milliers de lignes historiques) ne le sont pas.
 * Une erreur de journal ne bloque jamais l'enregistrement lui-même.
 */
trait Journalise
{
    /** Colonnes techniques jamais affichées dans le journal */
    private static array $colonnesIgnorees = ['updated_at', 'created_at', 'donnees_formulaire', 'donnees_origine', 'last_modified_by'];

    public static function bootJournalise(): void
    {
        static::created(fn (Model $modele) => self::journaliser($modele, 'cree', self::valeursRenseignees($modele)));
        static::updated(fn (Model $modele) => self::journaliser($modele, 'modifie', self::differences($modele)));
        static::deleted(fn (Model $modele) => self::journaliser($modele, 'supprime', []));
    }

    private static function journaliser(Model $modele, string $action, array $changements): void
    {
        if (! Auth::check() || ! JournalClient::tableDisponible()) {
            return;
        }

        $idClient = $modele->getTable() === 'client' ? $modele->getKey() : $modele->getAttribute('id_client');
        if (blank($idClient) || ($action === 'modifie' && $changements === [])) {
            return;
        }

        try {
            JournalClient::create([
                'id_client' => (string) $idClient,
                'objet' => $modele->getTable(),
                'objet_id' => (string) $modele->getKey(),
                'action' => $action,
                'changements' => $changements ?: null,
                'user_id' => Auth::id(),
                'nom_utilisateur' => Auth::user()?->name,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Modification : [libellé => [avant, après]] pour chaque champ réellement changé. */
    private static function differences(Model $modele): array
    {
        $changements = [];
        foreach ($modele->getChanges() as $colonne => $apres) {
            if (in_array($colonne, self::$colonnesIgnorees, true)) {
                continue;
            }
            $avant = $modele->getOriginal($colonne);
            if (self::texte($avant) === self::texte($apres)) {
                continue;
            }
            $changements[self::libelle($modele, $colonne)] = [self::texte($avant), self::texte($apres)];
        }

        return $changements;
    }

    /** Création : les champs remplis (sans valeur « avant »). */
    private static function valeursRenseignees(Model $modele): array
    {
        $valeurs = [];
        foreach ($modele->getAttributes() as $colonne => $valeur) {
            if (in_array($colonne, self::$colonnesIgnorees, true) || blank($valeur) || $colonne === $modele->getKeyName()) {
                continue;
            }
            $valeurs[self::libelle($modele, $colonne)] = [null, self::texte($valeur)];
        }

        return array_slice($valeurs, 0, 40, true);
    }

    /** Libellé de la question du formulaire si elle existe (« Téléphone principal »), sinon le nom de la colonne. */
    private static function libelle(Model $modele, string $colonne): string
    {
        static $libelles = [];
        $formulaire = $modele->getTable() === 'client' ? 'client' : $modele->getTable();

        $libelles[$formulaire] ??= rescue(fn () => FormulaireChamp::pour($formulaire)->pluck('libelle', 'cle')->all(), [], false);

        return $libelles[$formulaire][$colonne] ?? $colonne;
    }

    private static function texte(mixed $valeur): ?string
    {
        return match (true) {
            $valeur === null, $valeur === '' => null,
            is_bool($valeur) => $valeur ? 'Oui' : 'Non',
            $valeur instanceof \DateTimeInterface => $valeur->format('d/m/Y H:i'),
            is_array($valeur) => json_encode($valeur, JSON_UNESCAPED_UNICODE),
            default => mb_substr((string) $valeur, 0, 500),
        };
    }
}
