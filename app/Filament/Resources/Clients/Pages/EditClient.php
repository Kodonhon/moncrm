<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditClient extends EditRecord
{
    use Concerns\VilleObligatoire;

    protected static string $resource = ClientResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->remplirVille($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->completerLocalite($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('basculerVisibilite')
                ->label(fn () => $this->record->visibilite_client === false ? 'Réactiver' : 'Désactiver')
                ->icon(fn () => $this->record->visibilite_client === false ? Heroicon::OutlinedEye : Heroicon::OutlinedEyeSlash)
                ->color(fn () => $this->record->visibilite_client === false ? 'success' : 'danger')
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['visibilite_client' => $this->record->visibilite_client === false]);
                    $this->refreshFormData(['visibilite_client']);
                }),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
