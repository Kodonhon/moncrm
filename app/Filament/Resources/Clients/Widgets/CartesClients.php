<?php

namespace App\Filament\Resources\Clients\Widgets;

use App\Support\TableauDeBordDonnees;
use Filament\Widgets\Widget;

/** Quatre cartes en tête de la liste des clients (mêmes chiffres que le tableau de bord, mis en cache). */
class CartesClients extends Widget
{
    protected string $view = 'filament.clients.cartes';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $d = TableauDeBordDonnees::calculer();
        $etapes = collect($d['etapes'])->mapWithKeys(fn ($e) => [$e[0] => $e[1]]);

        return [
            'total' => $d['total'],
            'semaine' => $d['semaine'],
            'kyc' => $etapes['KYC faits'] ?? 0,
            'dimensionnes' => $etapes['Dimensionnés'] ?? 0,
            // Contrats signés (à la place des installés), dans le périmètre de l'utilisateur
            'contrats' => \App\Models\Contrat::tableDisponible()
                ? \Illuminate\Support\Facades\Cache::remember('cartes-clients-contrats:'.(auth()->id() ?? 'console'), now()->addMinutes(5),
                    fn () => \App\Models\Contrat::query()->count())
                : null,
            'enCours' => $d['en_cours'],
        ];
    }
}
