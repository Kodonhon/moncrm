<?php

namespace App\Photos;

use App\Models\FormulaireChamp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Paramètres des photos, par formulaire (table) qui contient des questions « Photo » :
 *   • lien du dossier Google Drive qui reçoit une copie des photos ;
 *   • modèle du nom de fichier, ex. « {table}_{champ}_{date}_{nom_client}_{id_client} ».
 * Enregistrés dans storage/app/parametres/photos.json (aucune modification de la base).
 */
class ParametresPhotos
{
    public const FICHIER = 'parametres/photos.json';

    public const MODELE_PAR_DEFAUT = '{table}_{champ}_{date}_{nom_client}_{id_client}';

    /** Variables utilisables dans le modèle de nom. */
    public const VARIABLES = [
        '{table}' => 'Table / formulaire (ex. kyc)',
        '{champ}' => 'Nom du champ (ex. photo_id_face1)',
        '{question}' => 'Libellé de la question (ex. Recto-de-la-piece)',
        '{date}' => 'Date du jour (AAAA-MM-JJ)',
        '{heure}' => 'Heure (HHhMM)',
        '{nom_client}' => 'Nom et prénoms du client',
        '{id_client}' => 'N° du client',
    ];

    /** Noms affichés des formulaires connus. */
    public const LIBELLES = [
        'client' => 'Client',
        'kyc' => 'KYC',
        'eval_site' => 'Dimensionnement',
        'credit_scoring' => 'Credit scoring',
    ];

    /** @return array<string, array{lien: ?string, modele: string, actif: bool}> */
    public static function tous(): array
    {
        $enregistres = Cache::rememberForever('parametres-photos', function () {
            $disque = Storage::disk('local');

            return $disque->exists(self::FICHIER) ? (json_decode($disque->get(self::FICHIER), true) ?: []) : [];
        });

        return collect(self::formulairesAvecPhotos())
            ->mapWithKeys(fn (string $formulaire) => [$formulaire => array_merge(
                ['lien' => null, 'modele' => self::MODELE_PAR_DEFAUT, 'actif' => false],
                $enregistres[$formulaire] ?? [],
            )])
            ->all();
    }

    /** @return array{lien: ?string, modele: string, actif: bool} */
    public static function pour(string $formulaire): array
    {
        return self::tous()[$formulaire] ?? ['lien' => null, 'modele' => self::MODELE_PAR_DEFAUT, 'actif' => false];
    }

    public static function enregistrer(array $parametres): void
    {
        Storage::disk('local')->put(self::FICHIER, json_encode($parametres, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Cache::forget('parametres-photos');
    }

    /** Formulaires (tables) qui ont au moins une question « Photo ». */
    public static function formulairesAvecPhotos(): array
    {
        return DB::table('formulaire_champ')->where('type', 'photo')->distinct()->orderBy('formulaire')->pluck('formulaire')->all();
    }

    public static function libelle(string $formulaire): string
    {
        return self::LIBELLES[$formulaire] ?? Str::headline($formulaire);
    }

    /**
     * Nom du fichier d'après le modèle du formulaire (sans extension), sans accents ni caractères spéciaux.
     *
     * @param  array<string, mixed>  $etat  réponses du formulaire (pour le nom d'un client en cours de saisie)
     */
    public static function nomFichier(string $formulaire, FormulaireChamp $champ, ?Model $client = null, array $etat = [], ?string $modele = null): string
    {
        $modele = $modele ?: (self::pour($formulaire)['modele'] ?: self::MODELE_PAR_DEFAUT);
        $nomClient = $client
            ? trim($client->nom_client.' '.$client->prenoms_client)
            : trim(($etat['nom_client'] ?? '').' '.($etat['prenoms_client'] ?? ''));

        $valeurs = [
            '{table}' => $formulaire,
            '{champ}' => $champ->cle,
            '{question}' => $champ->libelle,
            '{date}' => now()->format('Y-m-d'),
            '{heure}' => now()->format('H\hi'),
            '{nom_client}' => $nomClient,
            '{id_client}' => (string) ($client?->getKey() ?? ''),
        ];

        // Les valeurs n'introduisent pas de « _ » : seuls ceux du modèle séparent les morceaux
        $valeurs = array_map(fn ($valeur) => str_replace('_', '-', (string) $valeur), $valeurs);
        $valeurs['{champ}'] = $champ->cle;
        $valeurs['{table}'] = $formulaire;

        $nom = collect(explode('_', strtr($modele, $valeurs)))
            ->map(fn (string $morceau) => trim(preg_replace('/[^A-Za-z0-9_.-]+/', '-', Str::ascii($morceau)), '-.'))
            ->filter() // une variable vide (ex. n° d'un client pas encore enregistré) ne laisse pas « __ »
            ->implode('_');

        return Str::limit($nom ?: $champ->cle, 150, '');
    }

    /** Identifiant du dossier dans un lien Google Drive (…/folders/ID ou ?id=ID), ou l'identifiant lui-même. */
    public static function idDossierDrive(?string $lien): ?string
    {
        $lien = trim((string) $lien);

        return match (true) {
            $lien === '' => null,
            (bool) preg_match('~/folders/([A-Za-z0-9_-]{10,})~', $lien, $m) => $m[1],
            (bool) preg_match('~[?&]id=([A-Za-z0-9_-]{10,})~', $lien, $m) => $m[1],
            (bool) preg_match('~^[A-Za-z0-9_-]{10,}$~', $lien) => $lien,
            default => null,
        };
    }

    /* Journal des derniers envois vers Drive (affiché dans Paramètres > Photos) */

    public static function journaliser(string $formulaire, string $fichier, bool $ok, ?string $detail = null): void
    {
        $journal = Cache::get('photos-drive-journal', []);
        array_unshift($journal, ['date' => now()->format('d/m/Y H:i'), 'formulaire' => $formulaire, 'fichier' => $fichier, 'ok' => $ok, 'detail' => $detail]);
        Cache::forever('photos-drive-journal', array_slice($journal, 0, 30));
    }

    public static function journal(): array
    {
        return Cache::get('photos-drive-journal', []);
    }
}
