<?php

namespace App\Filament\Clusters\Parametres\Resources\Pages;

use App\Filament\Clusters\Parametres\Resources\FormulaireKycResource;

class GererFormulaireKyc extends GererFormulaireClient
{
    protected static string $resource = FormulaireKycResource::class;

    /** Le KYC se remplit depuis la fiche d'un client : pas d'aperçu direct. */
    protected function urlApercu(): ?string
    {
        return null;
    }
}
