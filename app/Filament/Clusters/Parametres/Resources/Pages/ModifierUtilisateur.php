<?php

namespace App\Filament\Clusters\Parametres\Resources\Pages;

use App\Filament\Clusters\Parametres\Resources\UtilisateurResource;
use App\Models\Role;
use App\Support\Acces;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class ModifierUtilisateur extends EditRecord
{
    protected static string $resource = UtilisateurResource::class;

    /** On ne peut pas se retirer soi-même le rôle Administrateur (pour ne pas perdre l'accès). */
    protected function beforeSave(): void
    {
        if (! $this->record->is(auth()->user())) {
            return;
        }

        $admin = Role::where('code', 'administrateur')->value('id');
        $roles = array_map('intval', (array) ($this->data['roles'] ?? []));

        if ($admin && $this->record->roles()->whereKey($admin)->exists() && ! in_array($admin, $roles, true)) {
            Notification::make()->title('Vous ne pouvez pas vous retirer le rôle Administrateur.')->danger()->send();

            throw new Halt;
        }
    }

    protected function afterSave(): void
    {
        Acces::oublier();
    }

    protected function getRedirectUrl(): string
    {
        return UtilisateurResource::getUrl('index');
    }
}
