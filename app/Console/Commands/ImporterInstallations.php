<?php

namespace App\Console\Commands;

use App\Models\Installation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('crm:importer-installations
    {fichier : Export AppSheet des installations (.csv, séparateur « ; »)}
    {--simulation : Afficher ce qui serait fait, sans rien écrire}')]
#[Description('Importe les rapports d\'installation (AppSheet) : les clients installés sortent des listes en attente')]
class ImporterInstallations extends Command
{
    /** Colonne de l'export AppSheet → colonne de la table installation. */
    private const COLONNES = [
        'ID installation' => 'id_installation',
        'Numéro de CNI du client' => 'num_cni',
        'Numéro du contrat' => 'num_contrat',
        'num_contrat_upya' => 'num_contrat_upya',
        'Type d\'installation' => 'type_installation',
        'Type de produit installé' => 'type_produit',
        'Type de paiement' => 'type_paiement',
        'Ville du client' => 'ville',
        'Localité du client' => 'localite',
        'Géolocalisation' => 'geolocalisation',
        'Installation prise en charge par' => 'prise_en_charge_par',
        'Installation assignée à' => 'assignee_a',
        'Statut du technicien' => 'statut_technicien',
        'Si non, pourqoui ?' => 'raison_non_installation',
        'Cause de la non installation' => 'cause_non_installation',
    ];

    /** Longueurs maximales (database/sql/installation.sql) ; 150 par défaut. */
    private const LONGUEURS = [
        'id_installation' => 20, 'num_cni' => 50, 'num_contrat' => 30, 'num_contrat_upya' => 30, 'type_installation' => 30,
        'type_paiement' => 20, 'geolocalisation' => 60, 'statut_technicien' => 30, 'ville' => 100,
        'raison_non_installation' => 2000, 'cause_non_installation' => 2000,
    ];

    /** Numéros de série : chiffres groupés par des espaces dans l'export (« 864 107 079 857 460 »). */
    private const SERIES = [
        'Code bare du CSB/CSD' => 'code_controleur',
        'Numero de serie de la pompe' => 'num_serie_pompe',
        'Numero de serie des panneaux 1' => 'num_serie_panneau_1',
        'Numero de serie des panneaux 2' => 'num_serie_panneau_2',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('installation')) {
            $this->error('Exécutez d\'abord le script database/sql/installation.sql sur la base.');

            return self::FAILURE;
        }

        $fichier = $this->argument('fichier');
        if (! is_file($fichier)) {
            $this->error("Fichier introuvable : {$fichier}");

            return self::FAILURE;
        }

        $lignes = $this->lire($fichier);
        $rattachement = $this->rattacheur();
        $stats = ['chemin' => 0, 'cni' => 0, 'telephone' => 0, 'introuvable' => 0];

        $enregistrements = [];
        foreach ($lignes as $l) {
            [$idClient, $par] = $rattachement($l);
            $stats[$par]++;
            $enregistrements[] = $this->enregistrement($l, $idClient);
        }

        // Un même rapport présent deux fois dans l'export : on garde la dernière ligne
        $enregistrements = collect($enregistrements)->keyBy('id_installation')->values()->all();

        $installes = collect($enregistrements)->where('systeme_installe', 1);
        $this->info(count($lignes).' rapports d\'installation, dont '.$installes->count().' « système installé ».');
        $this->line(sprintf('  Rattachés à un client : %d par le dossier des photos, %d par la pièce d\'identité, %d par le téléphone ; %d introuvables (gardés sans client).',
            $stats['chemin'], $stats['cni'], $stats['telephone'], $stats['introuvable']));
        $this->line('  Clients installés retrouvés dans le CRM : '.$installes->pluck('id_client')->filter()->unique()->count().'.');

        if ($this->option('simulation')) {
            $this->info('Simulation : aucune modification.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($enregistrements) {
            foreach (array_chunk($enregistrements, 300) as $paquet) {
                DB::table('installation')->upsert($paquet, ['id_installation'], array_keys(array_diff_key($paquet[0], ['id_installation' => true, 'created_at' => true])));
            }
        });

        Cache::forget('table-installation-disponible');
        Cache::forget('installations-compteurs-onglets'); // compteurs des onglets de la liste
        Cache::forget('indicateurs-installations');
        // Compteurs des menus (une clé par utilisateur) : recalculés au plus tard dans une minute
        $this->info('Import terminé : '.DB::table('installation')->count().' rapports en base.');

        return self::SUCCESS;
    }

    /**
     * Retrouve le client : 1) son numéro dans le dossier des photos (…/INSTALLATION/<n° client><pièce>/…),
     * 2) la pièce d'identité de son KYC, 3) son téléphone. Renvoie [id_client|null, moyen].
     */
    private function rattacheur(): \Closure
    {
        // Clés converties en texte : un n° client tout en chiffres ne doit pas devenir un entier
        $clients = DB::table('client')->pluck('id_client')->mapWithKeys(fn ($id) => [(string) $id => true])->all();
        $parPiece = DB::table('kyc')->where('visibilite_kyc', 1)->whereNotNull('num_piece_id')->get(['num_piece_id', 'id_client'])
            ->mapWithKeys(fn ($k) => [strtoupper(trim($k->num_piece_id)) => (string) $k->id_client])->all();
        $parTelephone = DB::table('client')->whereNotNull('contact1_client')->get(['contact1_client', 'id_client'])
            ->mapWithKeys(fn ($c) => [substr(preg_replace('/\D/', '', $c->contact1_client), -10) => (string) $c->id_client])->all();

        return function (array $l) use ($clients, $parPiece, $parTelephone): array {
            $piece = strtoupper(trim($l['Numéro de CNI du client'] ?? ''));

            if ($piece !== '') {
                foreach ($l as $valeur) {
                    if (preg_match('~INSTALLATION%2F([0-9A-Za-z]+?)'.preg_quote(rawurlencode($piece), '~').'%2F~', (string) $valeur, $m)
                        && isset($clients[$m[1]])) {
                        return [(string) $m[1], 'chemin'];
                    }
                }
                if (isset($parPiece[$piece])) {
                    return [$parPiece[$piece], 'cni'];
                }
            }

            $telephone = substr(self::chiffres($l['contact_client'] ?? ''), -10);
            if (strlen($telephone) === 10 && isset($parTelephone[$telephone])) {
                return [$parTelephone[$telephone], 'telephone'];
            }

            return [null, 'introuvable'];
        };
    }

    private function enregistrement(array $l, ?string $idClient): array
    {
        $ligne = ['id_client' => $idClient];

        foreach (self::COLONNES as $source => $colonne) {
            $valeur = trim((string) ($l[$source] ?? ''));
            $ligne[$colonne] = $valeur === '' ? null : mb_substr($valeur, 0, self::LONGUEURS[$colonne] ?? 150);
        }
        foreach (self::SERIES as $source => $colonne) {
            $valeur = str_replace([' ', "\u{202F}", "\u{00A0}"], '', trim((string) ($l[$source] ?? '')));
            $ligne[$colonne] = $valeur === '' ? null : mb_substr($valeur, 0, 60);
        }

        $ligne['contact_client'] = self::chiffres($l['contact_client'] ?? '') ?: null;
        $ligne['systeme_installe'] = self::ouiNon($l['Le système a-t-il été installé ?'] ?? '');
        $ligne['kit_recu'] = self::ouiNon($l['Le KIT a t-il été reçu par le client ?'] ?? '');
        $ligne['date_installation'] = self::date($l['Date d\'installation'] ?? '');
        $ligne['donnees_origine'] = json_encode(array_filter($l, fn ($v) => trim((string) $v) !== ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ligne['created_at'] = now();
        $ligne['updated_at'] = now();

        return $ligne;
    }

    /** « 2 250 758 372 120,00 » → « 2250758372120 ». */
    private static function chiffres(string $valeur): string
    {
        return preg_replace('/\D/', '', preg_replace('/,\d+$/', '', trim($valeur)));
    }

    private static function ouiNon(string $valeur): ?int
    {
        return match (mb_strtoupper(trim($valeur))) {
            'OUI', 'YES', 'TRUE', '1' => 1,
            'NON', 'NO', 'FALSE', '0' => 0,
            default => null,
        };
    }

    private static function date(string $valeur): ?string
    {
        $valeur = trim($valeur);
        if ($valeur === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y', $valeur)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Export AppSheet : séparateur « ; », en-têtes pouvant contenir un retour à la ligne. */
    private function lire(string $fichier): array
    {
        $flux = fopen($fichier, 'r');
        $entete = array_map(fn ($e) => trim($e, "\u{FEFF} \"\r\n"), fgetcsv($flux, 0, ';', '"', ''));
        $lignes = [];

        while (($valeurs = fgetcsv($flux, 0, ';', '"', '')) !== false) {
            if ($valeurs === [null]) {
                continue;
            }
            $valeurs = array_slice(array_pad($valeurs, count($entete), ''), 0, count($entete));
            $ligne = array_combine($entete, $valeurs);
            if (trim($ligne['ID installation'] ?? '') !== '') {
                $lignes[] = $ligne;
            }
        }

        fclose($flux);

        return $lignes;
    }
}
