<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;

/**
 * Fiche client : le nom, le statut et le bouton « Actions » sont dans la carte « Fiche client »
 * (voir ClientInfolist) ; la page n'affiche que le fil d'Ariane au-dessus.
 */
class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    /** Titre de l'onglet du navigateur. */
    public function getTitle(): string|Htmlable
    {
        return $this->record->nom_complet;
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getBreadcrumbs(): array
    {
        return [
            ClientResource::getUrl() => 'Clients',
            $this->record->nom_complet,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Un dimensionnement, un scoring ou un contrat change sous la fiche : on relit le client
     * pour que le parcours, la prochaine action et l'historique en haut de la fiche suivent.
     */
    #[On(self::EVENEMENT_MODIFIEE)]
    public function rafraichirFiche(): void
    {
        $this->record = $this->record->fresh();
    }

    public const EVENEMENT_MODIFIEE = 'fiche-client-modifiee';
}
