<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

#[Signature('crm:importer
    {dossier? : Dossier contenant les fichiers CSV (par défaut : Téléchargements\Table CRM)}
    {--remplacer : Vider les tables importées avant l\'import}
    {--seulement= : Tables à importer, séparées par des virgules (ex. type_vente,kyc). Par défaut : toutes}')]
#[Description('Importe les tables GREENO (client et tables de référence) depuis les fichiers CSV')]
class ImporterDonnees extends Command
{
    /** Départements ivoiriens : sert à nommer chaque ville (id_ville) d'après la localité chef-lieu du groupe. */
    private const DEPARTEMENTS = [
        'ABENGOUROU', 'ABIDJAN', 'ABOISSO', 'ADIAKE', 'ADZOPE', 'AGBOVILLE', 'AGNIBILEKROU', 'AKOUPE', 'ALEPE',
        'ARRAH', 'ATTIEGOUAKRO', 'BANGOLO', 'BEOUMI', 'BETTIE', 'BIANKOUMA', 'BLOLEQUIN', 'BOCANDA', 'BONDOUKOU',
        'BONGOUANOU', 'BONON', 'BOTRO', 'BOUAFLE', 'BOUAKE', 'BOUNA', 'BOUNDIALI', 'BUYO', 'DABAKALA', 'DABOU',
        'DALOA', 'DANANE', 'DAOUKRO', 'DIANRA', 'DIDIEVI', 'DIKODOUGOU', 'DIMBOKRO', 'DIVO', 'DJEKANOU', 'DOROPO',
        'DUEKOUE', 'FACOBLY', 'FERKESSEDOUGOU', 'FRESCO', 'GAGNOA', 'GBELEBAN', 'GOHITAFLA', 'GRAND-BASSAM', 'GRAND-LAHOU',
        'GUEYO', 'GUIGLO', 'GUITRY', 'ISSIA', 'JACQUEVILLE', 'KANI', 'KANIASSO', 'KATIOLA', 'KONG', 'KORHOGO',
        'KORO', 'KOUASSI-KOUASSIKRO', 'KOUIBLY', 'KOUN-FAO', 'KOUNAHIRI', 'KOUTO', 'LAKOTA', 'MADINANI', 'MAN',
        'MANKONO', "M'BAHIAKRO", "M'BATTO", "M'BENGUE", 'MEAGUI', 'MINIGNAN', 'NASSIAN', 'NIAKARAMADOUGOU',
        'ODIENNE', 'OUANGOLODOUGOU', 'OUANINOU', 'OUELLE', 'OUME', 'PRIKRO', 'SAKASSOU', 'SAMATIGUILA',
        'SAN-PEDRO', 'SANDEGUE', 'SASSANDRA', 'SEGUELA', 'SEGUELON', 'SIKENSI', 'SINEMATIALI', 'SINFRA', 'SIPILOU',
        'SOUBRE', 'TAABO', 'TABOU', 'TAFIRE', 'TAI', 'TANDA', 'TEHINI', 'TENGRELA', 'TIAPOUM', 'TIASSALE',
        'TIEBISSOU', 'TOUBA', 'TOULEPLEU', 'TOUMODI', 'TRANSUA', 'VAVOUA', 'YAKASSE-ATTOBROU', 'YAMOUSSOUKRO',
        'ZOUAN-HOUNIEN', 'ZOUKOUGBEU', 'ZUENOULA',
    ];

    /** Ordre d'insertion (les tables référencées d'abord) ; l'ordre inverse sert au vidage. */
    private const TABLES = [
        'genre' => 'id_genre',
        'entite' => 'id_entite',
        'src_client' => 'id_src_client',
        'type_piece_id' => 'id_type_piece_id',
        'site_shop' => 'id_site_shop',
        'ville' => 'id_ville',
        'canal_vente' => 'id_canal_vente',
        'localite_commune' => 'id_localite_commune',
        'client' => 'id_client',
        'type_vente' => 'id_type_vente',
        'kyc' => 'id_kyc',
        'usage_kit' => 'id_usg_kit',
        'src_energie' => 'id_src_energie',
        'eval_site' => 'id_eval_site',
        'produit' => 'id_prod',
    ];

    /** Fichier CSV de chaque table (ville : construite à partir des localités). */
    private const FICHIERS = [
        'genre' => 'genre.csv',
        'entite' => 'entite.csv',
        'src_client' => 'src_client.csv',
        'type_piece_id' => 'type_piece_id.csv',
        'site_shop' => 'site_shop.csv',
        'ville' => 'localite_commune.csv',
        'canal_vente' => 'canal_de_vente.csv',
        'localite_commune' => 'localite_commune.csv',
        'client' => 'client.csv',
        'type_vente' => 'type_vente.csv',
        'kyc' => 'kyc.csv',
        'usage_kit' => 'usage_kit.csv',
        'src_energie' => 'src_energie.csv',
        'eval_site' => 'eval_site.csv',
        'produit' => 'produit.csv',
    ];

    /** Tables sans clé auto-incrémentée (pas de compteur à recaler). */
    private const CLES_TEXTE = ['client', 'kyc', 'eval_site'];

    private string $dossier;

    public function handle(): int
    {
        $this->dossier = rtrim($this->argument('dossier') ?? getenv('USERPROFILE').'\\Downloads\\Table CRM', '\\/');

        $tables = $this->option('seulement')
            ? array_values(array_intersect(array_keys(self::TABLES), array_map('trim', explode(',', $this->option('seulement')))))
            : array_keys(self::TABLES);

        if ($tables === []) {
            $this->error('Aucune table reconnue. Tables possibles : '.implode(', ', array_keys(self::TABLES)));

            return self::FAILURE;
        }

        try {
            $donnees = [];
            foreach ($tables as $table) {
                $lignes = $this->lire(self::FICHIERS[$table]);
                $donnees[$table] = match ($table) {
                    'ville' => $this->construireVilles($lignes),
                    'site_shop' => array_map(fn ($ligne) => $ligne + ['visibilite_site_shop' => 1], $lignes),
                    'eval_site' => $this->evaluationsRattachees($lignes),
                    default => $lignes,
                };
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dejaRemplies = collect($tables)->filter(fn ($table) => DB::table($table)->exists());
        if ($dejaRemplies->isNotEmpty() && ! $this->option('remplacer')) {
            $this->error('Tables déjà remplies : '.$dejaRemplies->implode(', ').'. Relancez avec --remplacer pour les vider avant l\'import.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($donnees, $tables) {
            if ($this->option('remplacer')) {
                // Les KYC dépendent des clients : vider les clients vide aussi leurs KYC
                $aVider = in_array('client', $tables, true) ? array_unique([...$tables, 'kyc']) : $tables;
                foreach (array_reverse(array_keys(self::TABLES)) as $table) {
                    if (in_array($table, $aVider, true)) {
                        DB::table($table)->delete();
                    }
                }
            }

            foreach ($tables as $table) {
                foreach (array_chunk($donnees[$table], 500) as $paquet) {
                    DB::table($table)->insert($paquet);
                }
                $this->line(sprintf('  %-18s %6s lignes', $table, number_format(count($donnees[$table]), 0, ',', ' ')));
            }
        });

        $this->recalerSequences($tables);
        $this->info('Import terminé.');

        return self::SUCCESS;
    }

    /** Une évaluation sans client ne peut pas être importée (elle ne s'afficherait nulle part). */
    private function evaluationsRattachees(array $lignes): array
    {
        $rattachees = array_values(array_filter($lignes, fn (array $ligne) => filled($ligne['id_client'] ?? null)));

        if ($ignorees = count($lignes) - count($rattachees)) {
            $this->warn("Évaluations du site ignorées car sans id_client : {$ignorees}");
        }

        return $rattachees;
    }

    /** Lit un CSV d'export (séparateur virgule, « NULL » pour les valeurs vides). */
    private function lire(string $fichier): array
    {
        $chemin = $this->dossier.DIRECTORY_SEPARATOR.$fichier;
        if (! is_file($chemin)) {
            throw new RuntimeException("Fichier introuvable : {$chemin}");
        }

        $flux = fopen($chemin, 'r');
        $entetes = array_map(fn ($entete) => trim($entete, "\xEF\xBB\xBF \""), fgetcsv($flux, escape: ''));
        $lignes = [];

        while (($valeurs = fgetcsv($flux, escape: '')) !== false) {
            if ($valeurs === [null]) {
                continue; // ligne vide
            }
            $lignes[] = array_combine($entetes, array_map(
                fn ($valeur) => ($valeur === 'NULL' || $valeur === '') ? null : $valeur,
                $valeurs,
            ));
        }

        fclose($flux);

        return $lignes;
    }

    /** Une ville par id_ville, nommée d'après la localité du groupe qui est un chef-lieu de département. */
    private function construireVilles(array $localites): array
    {
        $groupes = collect($localites)->groupBy('id_ville');
        $sansChefLieu = [];

        $villes = $groupes->map(function ($groupe, $idVille) use (&$sansChefLieu) {
            if ((int) $idVille === 0) {
                return ['id_ville' => 0, 'lib_ville' => 'Inconnue', 'visibilite_ville' => 0];
            }

            $chefLieu = $groupe->first(fn ($localite) => in_array(Str::upper(Str::ascii($localite['lib_localite'])), self::DEPARTEMENTS, true));

            if (! $chefLieu) {
                $sansChefLieu[] = $idVille;
            }

            return [
                'id_ville' => (int) $idVille,
                'lib_ville' => ($chefLieu ?? $groupe->first())['lib_localite'],
                'visibilite_ville' => 1,
            ];
        })->sortKeys()->values()->all();

        if ($sansChefLieu) {
            $this->warn('Villes nommées d\'après leur première localité (à vérifier dans Paramètres > Villes) : n° '.implode(', ', $sansChefLieu));
        }

        return $villes;
    }

    /** Après insertion d'identifiants explicites, les compteurs PostgreSQL repartent du plus grand identifiant. */
    private function recalerSequences(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table => $cle) {
            if (! in_array($table, $tables, true) || in_array($table, self::CLES_TEXTE, true)) {
                continue;
            }
            DB::statement("select setval(pg_get_serial_sequence('{$table}', '{$cle}'), (select coalesce(max({$cle}), 0) + 1 from {$table}), false)");
        }
    }
}
