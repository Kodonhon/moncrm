<?php

namespace App\Filament\Resources\Messages\Pages;

use App\Filament\Resources\Messages\SmsModeleResource;
use App\Models\SmsModele;
use Filament\Resources\Pages\CreateRecord;

/** Un nouveau modèle part directement en approbation. */
class CreerModeleSms extends CreateRecord
{
    protected static string $resource = SmsModeleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'cree_par' => auth()->id(), 'approbation' => 'en_attente'];
    }

    protected function afterCreate(): void
    {
        /** @var SmsModele $modele */
        $modele = $this->record;
        SmsModeleResource::notifierApprobateurs($modele);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Modèle créé et envoyé en approbation';
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
