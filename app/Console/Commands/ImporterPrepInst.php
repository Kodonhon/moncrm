<?php

namespace App\Console\Commands;

use App\Models\PrepInst;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('crm:importer-prep-inst
    {fichier=storage/app/imports/prep_inst.csv : Export CSV de la table prep_inst d\'origine}
    {--simulation : Afficher ce qui serait fait, sans rien écrire}')]
#[Description('Importe l\'historique des appels CX de préparation installation (table prep_inst d\'origine)')]
class ImporterPrepInst extends Command
{
    private const COLONNES = [
        'id_prep', 'idcx_appsheet', 'date_prep', 'heure_prep', 'id_agent_traitant', 'id_prospect', 'id_type_req', 'nom_prenom',
        'cni_client', 'id_agent_vente', 'contact1', 'contact2', 'ville', 'localite', 'id_localite', 'id_produit', 'id_src_eau',
        'tdh_correct', 'client_reservoir', 'client_supp_reserv', 'compatibilite_pompe', 'resul_prep_inst', 'si_pas_pret_raison',
        'statut_prepa_inst', 'commentaire_prepa_inst', 'visibilite', 'last_modified_by', 'num_contrat_upya',
    ];

    private const ENTIERS = [
        'id_agent_traitant', 'id_type_req', 'id_agent_vente', 'id_localite', 'id_produit', 'id_src_eau', 'tdh_correct',
        'client_reservoir', 'client_supp_reserv', 'compatibilite_pompe', 'resul_prep_inst', 'statut_prepa_inst', 'visibilite',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('prep_inst')) {
            $this->error('Exécutez d\'abord database/sql/prep_inst.sql sur la base.');

            return self::FAILURE;
        }

        $fichier = is_file(base_path($this->argument('fichier'))) ? base_path($this->argument('fichier')) : $this->argument('fichier');
        if (! is_file($fichier)) {
            $this->error("Fichier introuvable : {$this->argument('fichier')}");

            return self::FAILURE;
        }

        $flux = fopen($fichier, 'r');
        $entete = array_map(fn ($c) => trim($c, "\u{FEFF} \""), fgetcsv($flux, escape: ''));
        $clients = DB::table('client')->pluck('id_client')->flip();

        $lignes = [];
        while (($valeurs = fgetcsv($flux, escape: '')) !== false) {
            if (count($valeurs) !== count($entete)) {
                continue;
            }
            $l = array_map(fn ($v) => ($v === 'NULL' || $v === '') ? null : $v, array_combine($entete, $valeurs));

            $ligne = [];
            foreach (self::COLONNES as $c) {
                $ligne[$c] = $l[$c] ?? null;
            }
            foreach (self::ENTIERS as $c) {
                $ligne[$c] = is_numeric($ligne[$c]) ? (int) $ligne[$c] : null;
            }
            // localite : code de localité dans l'origine
            $ligne['id_localite'] ??= is_numeric($ligne['localite']) ? (int) $ligne['localite'] : null;
            $ligne['visibilite'] ??= 1;
            // Rattachement au client du CRM (même identifiant que le prospect d'origine)
            $ligne['id_client'] = isset($clients[$ligne['id_prospect']]) ? $ligne['id_prospect'] : null;
            $ligne['id_cs'] = null;

            $lignes[] = $ligne;
        }
        fclose($flux);

        $rattaches = count(array_filter($lignes, fn ($l) => $l['id_client'] !== null));
        $this->info(count($lignes).' appels dans le fichier, dont '.$rattaches.' rattachés à un client du CRM.');

        if ($this->option('simulation')) {
            $this->info('Simulation : aucune modification.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($lignes) {
            $colonnes = array_values(array_diff(array_merge(self::COLONNES, ['id_client']), ['id_prep']));
            foreach (array_chunk($lignes, 500) as $paquet) {
                DB::table('prep_inst')->upsert($paquet, ['id_prep'], $colonnes);
            }
        });

        Cache::forget('table-prep-inst-disponible');
        $this->info('Import terminé : '.DB::table('prep_inst')->count().' appels en base.');

        return self::SUCCESS;
    }
}
