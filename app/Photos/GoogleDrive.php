<?php

namespace App\Photos;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Envoi de fichiers vers Google Drive avec un compte de service Google (clé JSON),
 * par l'API REST de Drive (aucune librairie supplémentaire).
 *
 * Mise en place : créer un compte de service dans Google Cloud (API Google Drive activée),
 * télécharger sa clé JSON dans storage/app/google/drive.json (ou GOOGLE_DRIVE_CLE dans .env),
 * puis ajouter l'e-mail du compte de service comme « Gestionnaire de contenu » du Drive partagé
 * qui contient les dossiers. Un compte de service n'a pas d'espace de stockage : il faut un Drive partagé.
 */
class GoogleDrive
{
    private const PORTEE = 'https://www.googleapis.com/auth/drive';

    public static function cheminCle(): string
    {
        return config('services.google_drive.cle') ?: storage_path('app/google/drive.json');
    }

    public static function estConfigure(): bool
    {
        return is_file(self::cheminCle());
    }

    /** E-mail du compte de service, à ajouter aux dossiers Drive. */
    public static function emailCompteService(): ?string
    {
        return self::estConfigure() ? (self::cle()['client_email'] ?? null) : null;
    }

    /** Nom du dossier (vérifie que le compte de service y a accès). */
    public function nomDossier(string $idDossier): string
    {
        $reponse = Http::withToken($this->jeton())
            ->get("https://www.googleapis.com/drive/v3/files/{$idDossier}", ['fields' => 'id,name,mimeType', 'supportsAllDrives' => 'true']);

        if ($reponse->failed()) {
            throw new RuntimeException(self::messageErreur($reponse->json(), $reponse->status()));
        }

        return (string) $reponse->json('name');
    }

    /** Envoie un fichier dans le dossier ; renvoie l'identifiant du fichier créé dans Drive. */
    public function envoyer(string $contenu, string $nom, string $typeMime, string $idDossier): string
    {
        $reponse = Http::withToken($this->jeton())
            ->attach('metadata', json_encode(['name' => $nom, 'parents' => [$idDossier]]), null, ['Content-Type' => 'application/json; charset=UTF-8'])
            ->attach('file', $contenu, $nom, ['Content-Type' => $typeMime])
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id');

        if ($reponse->failed()) {
            throw new RuntimeException(self::messageErreur($reponse->json(), $reponse->status()));
        }

        return (string) $reponse->json('id');
    }

    /** Jeton d'accès OAuth du compte de service (JWT signé RS256), gardé 50 minutes. */
    private function jeton(): string
    {
        return Cache::remember('google-drive-jeton', now()->addMinutes(50), function () {
            $cle = self::cle();
            $b64 = fn (string $texte) => rtrim(strtr(base64_encode($texte), '+/', '-_'), '=');
            $maintenant = time();

            $entete = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $charge = $b64(json_encode([
                'iss' => $cle['client_email'],
                'scope' => self::PORTEE,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $maintenant,
                'exp' => $maintenant + 3600,
            ]));

            if (! openssl_sign("{$entete}.{$charge}", $signature, $cle['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Clé privée du compte de service illisible.');
            }

            $reponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$entete}.{$charge}.".$b64($signature),
            ]);

            if ($reponse->failed()) {
                throw new RuntimeException('Connexion à Google refusée : '.($reponse->json('error_description') ?? $reponse->body()));
            }

            return (string) $reponse->json('access_token');
        });
    }

    private static function cle(): array
    {
        if (! self::estConfigure()) {
            throw new RuntimeException('Clé du compte de service Google absente ('.self::cheminCle().').');
        }

        $cle = json_decode((string) file_get_contents(self::cheminCle()), true);
        if (empty($cle['client_email']) || empty($cle['private_key'])) {
            throw new RuntimeException('Clé du compte de service Google invalide.');
        }

        return $cle;
    }

    private static function messageErreur(?array $json, int $statut): string
    {
        $message = $json['error']['message'] ?? 'erreur '.$statut;

        return match (true) {
            $statut === 404 => 'Dossier introuvable : partagez-le avec '.self::emailCompteService().'.',
            str_contains($message, 'storage quota') => 'Le compte de service n\'a pas d\'espace : utilisez un dossier d\'un Drive partagé.',
            default => 'Google Drive : '.$message,
        };
    }
}
