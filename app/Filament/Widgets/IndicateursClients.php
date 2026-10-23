<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class IndicateursClients extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /** Pas de rafraîchissement automatique (base distante). */
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $c = Client::query()
            ->where(fn ($q) => $q->whereNull('visibilite_client')->orWhere('visibilite_client', '<>', 0))
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when date_client >= ? then 1 else 0 end) as mois', [now()->startOfMonth()->toDateString()])
            ->selectRaw('sum(case when date_client >= ? and date_client < ? then 1 else 0 end) as mois_precedent', [
                now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->startOfMonth()->toDateString(),
            ])
            ->selectRaw('sum(case when date_client >= ? then 1 else 0 end) as semaine', [now()->subDays(7)->toDateString()])
            ->selectRaw('sum(case when genre_client = 0 then 1 else 0 end) as femmes')
            ->toBase()
            ->first();

        $evolution = $c->mois_precedent ? round(($c->mois - $c->mois_precedent) / $c->mois_precedent * 100) : null;
        $nombre = fn ($n) => number_format((int) $n, 0, ',', ' ');

        return [
            Stat::make('Clients', $nombre($c->total))
                ->description('+'.$nombre($c->semaine).' sur 7 jours')
                ->descriptionIcon(Heroicon::OutlinedUserPlus)
                ->color('primary'),
            Stat::make('Enregistrés ce mois-ci', $nombre($c->mois))
                ->description($evolution === null ? 'Mois précédent : '.$nombre($c->mois_precedent) : ($evolution >= 0 ? '+' : '').$evolution.' % vs mois précédent')
                ->descriptionIcon($evolution !== null && $evolution < 0 ? Heroicon::OutlinedArrowTrendingDown : Heroicon::OutlinedArrowTrendingUp)
                ->color($evolution !== null && $evolution < 0 ? 'danger' : 'success'),
            Stat::make('Part de femmes', $c->total ? round($c->femmes / $c->total * 100, 1).' %' : '—')
                ->description($nombre($c->femmes).' clientes')
                ->descriptionIcon(Heroicon::OutlinedUser)
                ->color('info'),
        ];
    }
}
