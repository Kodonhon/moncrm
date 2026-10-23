<?php

namespace App\Filament\Clusters\Parametres\Resources\Pages;

use App\Filament\Clusters\Parametres\Resources\RoleResource;
use App\Models\Role;
use App\Support\Acces;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class ModifierRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + ['permissions' => RoleResource::versMatrice($this->record)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Role $record */
        $matrice = $data['permissions'] ?? null;
        unset($data['permissions']);

        if ($record->systeme) {
            unset($data['code']);
        }

        $record->update($data);

        if ($record->estAdministrateur()) {
            $record->synchroniserPermissions(Acces::toutesLesPermissions());
        } elseif ($matrice !== null) {
            $record->synchroniserPermissions(RoleResource::depuisMatrice($matrice));
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->visible(fn () => RoleResource::canDelete($this->record))];
    }

    protected function getRedirectUrl(): string
    {
        return RoleResource::getUrl('index');
    }
}
