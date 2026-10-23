<?php

namespace App\Filament\Resources\Contrats\Pages;

use App\Filament\Resources\Contrats\ContratResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewContrat extends ViewRecord
{
    protected static string $resource = ContratResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Contrat '.$this->getRecord()->num_contrat;
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
