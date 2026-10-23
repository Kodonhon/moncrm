<?php

namespace App\Filament\Clusters\Parametres\Resources\Pages;

use App\Filament\Clusters\Parametres\Resources\UtilisateurResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListeUtilisateurs extends ListRecords
{
    protected static string $resource = UtilisateurResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouvel utilisateur')->icon(Heroicon::OutlinedUserPlus)];
    }

    public function getTabs(): array
    {
        return [
            'actifs' => Tab::make('Actifs')->icon(Heroicon::OutlinedCheckCircle)
                ->badge(fn () => User::where('actif', true)->count())->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('actif', true)),
            'sans_role' => Tab::make('Sans rôle')->icon(Heroicon::OutlinedExclamationTriangle)
                ->badge(fn () => User::whereDoesntHave('roles')->count())->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDoesntHave('roles')),
            'inactifs' => Tab::make('Inactifs')->icon(Heroicon::OutlinedPauseCircle)
                ->badge(fn () => User::where('actif', false)->count())->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('actif', false)),
            'tous' => Tab::make('Tous')->badge(fn () => User::count()),
        ];
    }
}
