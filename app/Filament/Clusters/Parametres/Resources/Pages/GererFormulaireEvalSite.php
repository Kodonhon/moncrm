<?php

namespace App\Filament\Clusters\Parametres\Resources\Pages;

use App\Filament\Clusters\Parametres\Resources\FormulaireEvalSiteResource;

class GererFormulaireEvalSite extends GererFormulaireClient
{
    protected static string $resource = FormulaireEvalSiteResource::class;

    /** L'évaluation se remplit depuis la fiche d'un client : pas d'aperçu direct. */
    protected function urlApercu(): ?string
    {
        return null;
    }
}
