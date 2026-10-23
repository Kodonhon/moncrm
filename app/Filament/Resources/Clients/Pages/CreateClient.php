<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    use Concerns\VilleObligatoire;

    protected static string $resource = ClientResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->completerLocalite($data);
    }

    protected static ?string $title = 'Ajouter un client';

    /** Après l'enregistrement, on ouvre la fiche du client pour enchaîner sur son KYC. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Client enregistré. Faites maintenant son KYC (en bas de la fiche).';
    }
}
