<?php

namespace App\Filament\Clusters\Parametres;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

/** Page unique d'une table de référence : liste + ajout/modification dans une fenêtre. */
abstract class PageGestionReference extends ManageRecords
{
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Ajouter')
                ->icon(Heroicon::OutlinedPlus)
                ->modalWidth('lg'),
        ];
    }
}
