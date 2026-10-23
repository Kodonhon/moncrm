<?php

namespace App\Jobs;

use App\Photos\GoogleDrive;
use App\Photos\ParametresPhotos;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copie une photo enregistrée dans le CRM vers le dossier Google Drive de son formulaire.
 * Lancé après la réponse (l'utilisateur n'attend pas l'envoi) ; un échec est noté dans
 * Paramètres > Photos, sans bloquer l'enregistrement : la photo reste dans le CRM.
 */
class EnvoyerPhotoDrive
{
    use Dispatchable;

    public function __construct(
        private string $formulaire,
        private string $disque,
        private string $chemin,
    ) {}

    public function handle(GoogleDrive $drive): void
    {
        $parametres = ParametresPhotos::pour($this->formulaire);
        $dossier = ParametresPhotos::idDossierDrive($parametres['lien']);

        if (! $parametres['actif'] || ! $dossier) {
            return;
        }

        $nom = basename($this->chemin);

        try {
            $stockage = Storage::disk($this->disque);
            $drive->envoyer($stockage->get($this->chemin), $nom, $stockage->mimeType($this->chemin) ?: 'image/jpeg', $dossier);
            ParametresPhotos::journaliser($this->formulaire, $nom, true);
        } catch (Throwable $e) {
            report($e);
            ParametresPhotos::journaliser($this->formulaire, $nom, false, $e->getMessage());
        }
    }
}
