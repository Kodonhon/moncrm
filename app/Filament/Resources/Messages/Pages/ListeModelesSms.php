<?php

namespace App\Filament\Resources\Messages\Pages;

use App\Filament\Messages\EnvoiSms;
use App\Filament\Resources\Messages\SmsModeleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListeModelesSms extends ListRecords
{
    protected static string $resource = SmsModeleResource::class;

    public function getTitle(): string
    {
        return 'Modèles de messages';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'Créer et gérer les modèles de SMS — approbation obligatoire avant envoi.';
    }

    protected function getHeaderActions(): array
    {
        return [
            EnvoiSms::action()->color('gray')->outlined(),
            CreateAction::make()->label('Nouveau modèle')->icon(Heroicon::OutlinedPlus),
        ];
    }
}
