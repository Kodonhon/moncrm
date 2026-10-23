<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Menu « ⋯ » de l'en-tête de la fiche client : modification et désactivation.
 * (Appeler / WhatsApp sont devenus des boutons directs de l'en-tête, voir ClientInfolist.)
 */
class ActionsClient
{
    public static function menu(): ActionGroup
    {
        return ActionGroup::make([
            ActionGroup::make([
                Action::make('modifierClient')
            ->authorize(fn () => \App\Support\Acces::peut('clients.modifier'))
                    ->label('Modifier le client')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Client $record) => ClientResource::getUrl('edit', ['record' => $record])),
            ])->dropdown(false),
            ActionGroup::make([
                Action::make('basculerVisibilite')
            ->authorize(fn () => \App\Support\Acces::peut('clients.supprimer'))
                    ->label(fn (Client $record) => $record->visibilite_client === false ? 'Réactiver le client' : 'Désactiver le client')
                    ->icon(fn (Client $record) => $record->visibilite_client === false ? Heroicon::OutlinedEye : Heroicon::OutlinedEyeSlash)
                    ->color(fn (Client $record) => $record->visibilite_client === false ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->action(function (Client $record) {
                        $record->update(['visibilite_client' => $record->visibilite_client === false]);
                        Notification::make()->title($record->visibilite_client ? 'Client réactivé' : 'Client désactivé')->success()->send();
                    }),
            ])->dropdown(false),
        ])
            ->label('Plus d\'actions')
            ->tooltip('Plus d\'actions')
            ->icon(Heroicon::EllipsisHorizontal)
            ->iconButton()
            ->color('gray')
            ->extraAttributes(['class' => 'gr-bouton-icone']);
    }
}
