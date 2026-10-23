<?php

namespace App\Filament\Clusters\Parametres\Resources\Pages;

use App\Filament\Clusters\Parametres\Resources\AgentResource;
use App\Models\Agent;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/** Paramètres > Gestion des agents : onglets Actifs / Inactifs / Tous. */
class GererAgents extends ManageRecords
{
    protected static string $resource = AgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Ajouter un agent')
                ->icon(Heroicon::OutlinedPlus)
                ->modalWidth('3xl'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'actifs' => Tab::make('Actifs')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->badge(fn () => Agent::query()->where('visibilite_agent', 1)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('visibilite_agent', 1)),
            'inactifs' => Tab::make('Inactifs')
                ->icon(Heroicon::OutlinedPauseCircle)
                ->badge(fn () => Agent::query()->where(fn ($q) => $q->where('visibilite_agent', 0)->orWhereNull('visibilite_agent'))->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn ($q) => $q->where('visibilite_agent', 0)->orWhereNull('visibilite_agent'))),
            'tous' => Tab::make('Tous')
                ->badge(fn () => Agent::query()->count()),
        ];
    }
}
