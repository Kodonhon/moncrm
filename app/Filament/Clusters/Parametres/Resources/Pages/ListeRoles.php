<?php

namespace App\Filament\Clusters\Parametres\Resources\Pages;

use App\Filament\Clusters\Parametres\Resources\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListeRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Créer')->icon(Heroicon::OutlinedPlus),
            // Export CSV (Excel) de la liste des rôles avec leurs permissions
            \Filament\Actions\Action::make('exporter')
                ->label('Exporter')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => response()->streamDownload(function () {
                    $sortie = fopen('php://output', 'w');
                    fwrite($sortie, "\u{FEFF}"); // BOM : accents corrects dans Excel
                    fputcsv($sortie, ['Titre', 'Code', 'Description', 'Données visibles', 'Utilisateurs', 'Permissions', 'Créé le'], ';');
                    foreach (\App\Models\Role::query()->withCount('utilisateurs')->with('permissions')->orderBy('nom')->get() as $role) {
                        fputcsv($sortie, [
                            $role->nom, $role->code, $role->description,
                            \App\Support\Acces::PERIMETRES[$role->perimetre][0] ?? $role->perimetre,
                            $role->utilisateurs_count,
                            $role->estAdministrateur() ? 'Toutes' : $role->permissions->pluck('permission')->map(fn ($p) => \App\Support\Acces::libellePermission($p))->implode(' | '),
                            $role->created_at?->format('d/m/Y H:i'),
                        ], ';');
                    }
                    fclose($sortie);
                }, 'roles-'.now()->format('Y-m-d').'.csv')),
        ];
    }
}
