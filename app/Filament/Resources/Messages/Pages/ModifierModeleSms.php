<?php

namespace App\Filament\Resources\Messages\Pages;

use App\Filament\Resources\Messages\SmsModeleResource;
use Filament\Resources\Pages\EditRecord;

/** Modifier le texte d'un modèle approuvé (ou rejeté) le renvoie en approbation. */
class ModifierModeleSms extends EditRecord
{
    protected static string $resource = SmsModeleResource::class;

    private bool $resoumis = false;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('contenu', $data) && trim((string) $data['contenu']) !== trim((string) $this->record->contenu)
            && in_array($this->record->approbation, ['approuve', 'rejete'], true)) {
            $this->resoumis = true;
            $data += [];
            $data['approbation'] = 'en_attente';
            $data['approuve_par'] = null;
            $data['approuve_le'] = null;
            $data['motif_rejet'] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->resoumis) {
            SmsModeleResource::notifierApprobateurs($this->record);
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return $this->resoumis ? 'Texte modifié : le modèle repart en approbation' : 'Modèle enregistré';
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
