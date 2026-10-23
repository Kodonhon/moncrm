<?php

namespace App\Filament\Clusters\Parametres\Resources\Pages;

use App\Filament\Clusters\Parametres\Resources\FormulaireCreditScoringResource;

class GererFormulaireCreditScoring extends GererFormulaireClient
{
    protected static string $resource = FormulaireCreditScoringResource::class;

    /** Le credit scoring se remplit depuis la fiche d'un client : pas d'aperçu direct. */
    protected function urlApercu(): ?string
    {
        return null;
    }
}
