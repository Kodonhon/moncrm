<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Paiements d'un contrat UPYA, lus dans la base des ventes (Railway, vue crm_solaire.paiement_contrat).
 * Lecture seule : le CRM n'écrit jamais dans cette base. Lien avec le CRM par le n° de contrat UPYA.
 */
class PaiementContrat extends Model
{
    protected $connection = 'ventes';

    protected $table = 'paiement_contrat';

    protected $primaryKey = 'num_contrat_upya';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date_deposit' => 'date',
            'mt_deposit' => 'decimal:2',
            'total_paye' => 'decimal:2',
            'premier_paiement' => 'datetime',
            'dernier_paiement' => 'datetime',
            'date_maj' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return config('database.connections.ventes.search_path', 'public').'.'.$this->table;
    }

    public const STATUTS = ['full_deposit' => 'Dépôt complet', 'partial_deposit' => 'Dépôt partiel'];

    public const ENTITES = ['upya_greeno' => 'Greeno', 'upya_tevia' => 'Tevia'];

    /** N° de contrat comparable entre les deux bases : chiffres seuls, sans zéros de tête (« 035164558 » = « 35164558 »). */
    public static function normaliser(?string $numero): string
    {
        return ltrim(preg_replace('/\D/', '', (string) $numero), '0');
    }

    /**
     * Clients du CRM par n° de contrat UPYA normalisé : [numéro => id_client], d'après les credit scorings,
     * les préparations d'installation et les rapports d'installation. Le KYC n'y figure pas :
     * sa « Référence UPYA » est la référence client (CI00…), pas le n° de contrat.
     */
    public static function clientsParContrat(): array
    {
        return Cache::remember('ventes-clients-par-contrat', now()->addMinutes(10), function () {
            // Boucle explicite : les n° sont des clés numériques, qu'un flatMap/array_merge renumérote
            $clients = [];
            foreach (['installation', 'prep_inst', 'credit_scoring'] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach (DB::table($table)->whereNotNull('num_contrat_upya')->whereNotNull('id_client')->get(['num_contrat_upya', 'id_client']) as $ligne) {
                    $numero = static::normaliser($ligne->num_contrat_upya);
                    if ($numero !== '') {
                        $clients[$numero] = (string) $ligne->id_client;
                    }
                }
            }

            return $clients;
        });
    }

    /** Tests : deposits simulés [n° normalisé => ['statut' => …, 'mt_deposit' => …]] au lieu de la base des ventes. */
    public static ?array $simulation = null;

    /**
     * Deposits par n° de contrat normalisé :
     * [numéro => ['statut' => full_deposit|partial_deposit, 'mt_deposit' => …, 'total_paye' => …, 'date_deposit' => …]].
     * null : base des ventes non configurée ou injoignable (la règle du deposit n'est alors pas appliquée).
     */
    public static function parNumero(): ?array
    {
        if (static::$simulation !== null) {
            return static::$simulation;
        }
        if (blank(config('database.connections.ventes.password')) || Cache::has('ventes-injoignable')) {
            return null;
        }

        try {
            return Cache::remember('ventes-deposits-par-numero-v2', now()->addMinutes(5), function () {
                $deposits = [];
                foreach (static::query()->toBase()->get(['num_contrat_upya', 'statut_amt', 'mt_deposit', 'total_paye', 'date_deposit']) as $ligne) {
                    $numero = static::normaliser($ligne->num_contrat_upya);
                    // Un même contrat écrit de deux façons : le dépôt complet l'emporte
                    if ($numero !== '' && ($deposits[$numero]['statut'] ?? null) !== 'full_deposit') {
                        $deposits[$numero] = [
                            'statut' => $ligne->statut_amt, 'mt_deposit' => $ligne->mt_deposit,
                            'total_paye' => $ligne->total_paye, 'date_deposit' => $ligne->date_deposit,
                        ];
                    }
                }

                return $deposits;
            });
        } catch (Throwable $e) {
            report($e);
            Cache::put('ventes-injoignable', true, now()->addMinute()); // pas une tentative par page

            return null;
        }
    }

    /** La règle « un client passe au crédit seulement avec un deposit complet » peut-elle être vérifiée ? */
    public static function verificationActive(): bool
    {
        return static::parNumero() !== null;
    }

    /** Deposit d'un n° de contrat UPYA (null : aucune ligne dans la base des ventes). */
    public static function depositDe(?string $numero): ?array
    {
        return static::parNumero()[static::normaliser($numero)] ?? null;
    }

    public static function depositComplet(?string $numero): bool
    {
        return (static::depositDe($numero)['statut'] ?? null) === 'full_deposit';
    }

    /** Client du CRM rattaché à ce contrat, s'il est connu. */
    public function idClient(): ?string
    {
        return static::clientsParContrat()[static::normaliser($this->num_contrat_upya)] ?? null;
    }

    /** La base des ventes est-elle joignable (mot de passe renseigné, serveur accessible) ? */
    public static function baseDisponible(): bool
    {
        if (blank(config('database.connections.ventes.password'))) {
            return false;
        }

        try {
            static::query()->limit(1)->exists();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    // Lecture seule : toute écriture est refusée
    public function save(array $options = []): bool
    {
        return false;
    }

    public function delete(): ?bool
    {
        return false;
    }
}
