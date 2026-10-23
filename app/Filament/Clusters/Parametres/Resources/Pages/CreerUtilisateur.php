<?php

namespace App\Filament\Clusters\Parametres\Resources\Pages;

use App\Filament\Clusters\Parametres\Resources\UtilisateurResource;
use Filament\Resources\Pages\CreateRecord;

class CreerUtilisateur extends CreateRecord
{
    protected static string $resource = UtilisateurResource::class;

    protected function getRedirectUrl(): string
    {
        return UtilisateurResource::getUrl('index');
    }
}
