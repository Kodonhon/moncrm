<?php

namespace App\Filament\Clusters\Parametres\Resources\Pages;

use App\Filament\Clusters\Parametres\Resources\RoleResource;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreerRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $matrice = $data['permissions'] ?? [];
        unset($data['permissions']);

        /** @var Role $role */
        $role = Role::create($data + ['systeme' => false]);
        $role->synchroniserPermissions(RoleResource::depuisMatrice($matrice));

        return $role;
    }

    protected function getRedirectUrl(): string
    {
        return RoleResource::getUrl('index');
    }
}
