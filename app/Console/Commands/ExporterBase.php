<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Signature('crm:exporter
    {--structure : Exporter uniquement la structure (sans les données)}
    {--pgadmin : Données en INSERT (fichier exécutable dans le Query Tool de pgAdmin)}
    {--pg-dump= : Chemin vers pg_dump (par défaut : celui de Laragon)}')]
#[Description('Exporte la base PostgreSQL du CRM (schéma public : structure + données) dans un fichier .sql')]
class ExporterBase extends Command
{
    public function handle(): int
    {
        $connexion = config('database.connections.'.config('database.default'));

        if (($connexion['driver'] ?? null) !== 'pgsql') {
            $this->error('La base active n\'est pas PostgreSQL : export impossible avec pg_dump.');

            return self::FAILURE;
        }

        $pgDump = $this->option('pg-dump') ?: 'C:\\laragon\\bin\\postgresql\\postgresql\\bin\\pg_dump.exe';

        if (! is_file($pgDump)) {
            $this->error("pg_dump introuvable : {$pgDump}");

            return self::FAILURE;
        }

        $dossier = base_path('exports');
        if (! is_dir($dossier)) {
            mkdir($dossier, 0755, true);
        }

        $suffixe = ($this->option('structure') ? '_structure' : '').($this->option('pgadmin') ? '_pgadmin' : '');
        $fichier = $dossier.DIRECTORY_SEPARATOR.'crm_greeno_'.now()->format('Y-m-d_His').$suffixe.'.sql';

        $arguments = [
            $pgDump,
            '--host='.$connexion['host'],
            '--port='.$connexion['port'],
            '--username='.$connexion['username'],
            '--dbname='.$connexion['database'],
            '--schema=public',   // uniquement les tables du CRM (pas les schémas internes de Supabase)
            '--no-owner',
            '--no-privileges',
            '--encoding=UTF8',
            '--file='.$fichier,
        ];

        if ($this->option('structure')) {
            $arguments[] = '--schema-only';
        }

        if ($this->option('pgadmin')) {
            $arguments[] = '--column-inserts';
        }

        $this->info('Export en cours depuis '.$connexion['host'].'…');

        $process = new Process($arguments, base_path(), [
            'PGPASSWORD' => (string) $connexion['password'],
            'PGSSLMODE' => $connexion['sslmode'] ?? 'prefer',
        ], null, 600);

        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($fichier);
            $this->error('Échec de l\'export : '.trim($process->getErrorOutput()));

            return self::FAILURE;
        }

        $this->info('Export terminé : '.$fichier.' ('.number_format(filesize($fichier) / 1024, 1, ',', ' ').' Ko)');

        return self::SUCCESS;
    }
}
