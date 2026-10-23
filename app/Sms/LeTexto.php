<?php

namespace App\Sms;

use App\Models\Client;
use App\Models\Contrat;
use App\Models\Produit;
use App\Models\SmsConfiguration;
use App\Models\SmsMessage;
use App\Models\SmsModele;
use App\Support\CacheReferences;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envoi des SMS par l'API LeTexto (https://apis.letexto.com/v1/messages/send?token=…&from=…&to=…&content=…).
 * L'adresse, la méthode et le nom des paramètres sont réglables dans Messages > Configuration,
 * au cas où LeTexto vous a donné une autre adresse (compte entreprise, campagnes…).
 */
class LeTexto
{
    /** Variables utilisables dans un modèle : {variable} => description */
    public const VARIABLES = [
        'nom' => 'Nom du client',
        'prenoms' => 'Prénoms du client',
        'nom_complet' => 'Nom et prénoms',
        'telephone' => 'Téléphone du client',
        'num_contrat' => 'N° de contrat CRM',
        'num_contrat_upya' => 'N° de contrat UPYA',
        'produit' => 'Produit du contrat',
        'montant' => 'Montant du contrat',
        'date' => 'Date du jour',
    ];

    /** Numéro au format international sans « + » : 2250701020304. null si invalide. */
    public static function normaliserNumero(?string $numero, ?string $indicatif = null): ?string
    {
        $indicatif ??= SmsConfiguration::actuelle()->indicatif ?: '225';
        $chiffres = preg_replace('/\D/', '', (string) $numero);
        $chiffres = preg_replace('/^00/', '', $chiffres);

        return match (true) {
            strlen($chiffres) === 10 => $indicatif.$chiffres,
            str_starts_with($chiffres, $indicatif) && strlen($chiffres) >= strlen($indicatif) + 8 => $chiffres,
            default => null,
        };
    }

    /** Valeurs des variables pour un client (dernier contrat pour les variables de contrat). */
    public static function valeurs(?Client $client, array $extra = []): array
    {
        $contrat = null;
        if ($client && Contrat::tableDisponible()) {
            $contrat = rescue(fn () => $client->contrats()->latest('date_contrat')->first(), null, false);
        }

        return array_merge([
            'nom' => $client?->nom_client,
            'prenoms' => $client?->prenoms_client,
            'nom_complet' => $client?->nom_complet,
            'telephone' => $client?->contact1_client,
            'num_contrat' => $contrat?->num_contrat,
            'num_contrat_upya' => $contrat?->num_contrat_upya,
            'produit' => $contrat ? CacheReferences::libelle(Produit::class, $contrat->id_prod) : null,
            'montant' => $contrat && filled($contrat->montant) ? number_format((float) $contrat->montant, 0, ',', ' ').' F' : null,
            'date' => now()->format('d/m/Y'),
        ], $extra);
    }

    /** Remplace {variable} par sa valeur ; une variable sans valeur est retirée. */
    public static function rendre(string $contenu, ?Client $client = null, array $extra = []): string
    {
        $valeurs = self::valeurs($client, $extra);

        $texte = preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => (string) ($valeurs[$m[1]] ?? ''), $contenu);

        return trim(preg_replace('/[ \t]{2,}/', ' ', $texte));
    }

    /** Nombre de SMS facturés : 160 caractères (153 en plusieurs parties), 70/67 si accents hors GSM. */
    public static function nombreSms(string $texte): int
    {
        $longueur = mb_strlen($texte);
        if ($longueur === 0) {
            return 0;
        }
        $gsm = ! preg_match('/[^\x{0020}-\x{007E}\x{00A3}\x{00A5}\x{00E8}\x{00E9}\x{00F9}\x{00EC}\x{00F2}\x{00C7}\x{00D8}\x{00F8}\x{00C5}\x{00E5}\x{00C4}\x{00D6}\x{00D1}\x{00DC}\x{00A7}\x{00E4}\x{00F6}\x{00F1}\x{00FC}\x{00E0}\x{00C9}\x{00DF}\x{00C6}\x{00E6}\n\r]/u', $texte);
        [$simple, $partie] = $gsm ? [160, 153] : [70, 67];

        return $longueur <= $simple ? 1 : (int) ceil($longueur / $partie);
    }

    public static function compteur(string $texte): string
    {
        $n = self::nombreSms($texte);

        return mb_strlen($texte).' caractères · '.$n.' SMS';
    }

    /**
     * Appelle l'API pour un numéro. Retourne [ok, code HTTP, corps de la réponse].
     *
     * @return array{0: bool, 1: int|null, 2: string}
     */
    public static function appeler(SmsConfiguration $config, string $numero, string $texte): array
    {
        $url = rtrim((string) $config->url_base, '/').'/'.ltrim((string) $config->chemin_envoi, '/');

        $parametres = array_filter([
            $config->param_expediteur ?: 'from' => $config->expediteur,
            $config->param_destinataire ?: 'to' => $numero,
            $config->param_message ?: 'content' => $texte,
        ], fn ($v) => $v !== null && $v !== '');

        if (filled($config->url_retour)) {
            $parametres['responseUrl'] = $config->url_retour;
        }

        $requete = Http::timeout(20)->acceptJson();

        if ($config->mode_auth === 'bearer') {
            $requete = $requete->withToken($config->token);
        } else {
            $parametres[$config->param_token ?: 'token'] = $config->token;
        }

        try {
            $reponse = strtoupper((string) $config->methode) === 'POST'
                ? $requete->asJson()->post($url, $parametres)
                : $requete->get($url, $parametres);

            $corps = Str::limit((string) $reponse->body(), 2000);
            $json = $reponse->json();
            // Certaines API répondent 200 avec un statut d'erreur dans le corps
            $erreurDansCorps = is_array($json) && (
                (isset($json['success']) && $json['success'] === false)
                || (isset($json['status']) && in_array(strtolower((string) $json['status']), ['error', 'failed', 'echec'], true))
            );

            return [$reponse->successful() && ! $erreurDansCorps, $reponse->status(), $corps];
        } catch (Throwable $e) {
            return [false, null, Str::limit($e->getMessage(), 2000)];
        }
    }

    /**
     * Envoie un SMS (ou le simule en mode test) et l'inscrit dans l'historique.
     * $destinataire : un client, ou un numéro saisi à la main.
     */
    public static function envoyer(Client|string $destinataire, string $contenu, ?SmsModele $modele = null, ?string $lot = null): SmsMessage
    {
        $config = SmsConfiguration::actuelle();
        $client = $destinataire instanceof Client ? $destinataire : null;
        $numero = self::normaliserNumero($client ? $client->contact1_client : $destinataire, $config->indicatif);
        $texte = self::rendre($contenu, $client);

        $message = new SmsMessage([
            'lot' => $lot,
            'sms_modele_id' => $modele?->getKey(),
            'id_client' => $client?->getKey(),
            'telephone' => $numero ?? (string) ($client ? $client->contact1_client : $destinataire),
            'contenu' => $texte,
            'nb_sms' => max(1, self::nombreSms($texte)),
            'envoye_par' => Auth::id(),
        ]);

        if (! $numero) {
            $message->fill(['statut' => 'echec', 'reponse_api' => 'Numéro de téléphone invalide.']);
        } elseif ($texte === '') {
            $message->fill(['statut' => 'echec', 'reponse_api' => 'Message vide.']);
        } elseif ($config->mode_test || ! $config->prete()) {
            $message->fill(['statut' => 'simule', 'envoye_le' => now(),
                'reponse_api' => $config->mode_test ? 'Mode test : aucun SMS envoyé.' : 'API non configurée ou désactivée : aucun SMS envoyé.']);
        } else {
            [$ok, $code, $corps] = self::appeler($config, $numero, $texte);
            $message->fill(['statut' => $ok ? 'envoye' : 'echec', 'code_http' => $code, 'reponse_api' => $corps, 'envoye_le' => now()]);
        }

        $message->save();

        return $message;
    }
}
