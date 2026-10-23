<?php

namespace App\Filament\Resources\Clients\Widgets;

use App\Models\Client;
use App\Models\EvalSite;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * Bandeau d'indicateurs au-dessus de la liste des clients.
 * Une seule requête (base distante), gardée 2 minutes en cache.
 */
class IndicateursListe extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $c = Cache::remember('indicateurs-liste-clients', now()->addMinutes(2), fn () => (array) Client::query()
            ->where(fn ($q) => $q->whereNull('visibilite_client')->orWhere('visibilite_client', '<>', 0))
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when date_client >= ? then 1 else 0 end) as nouveaux', [now()->subDays(7)->toDateString()])
            ->selectRaw('sum(case when id_client in (select id_client from kyc where visibilite_kyc = 1) then 1 else 0 end) as kyc')
            ->selectRaw('sum(case when id_client in (select id_client from eval_site) then 1 else 0 end) as evaluations')
            ->selectRaw('sum(case when id_client in (select id_client from eval_site where statut_eval_site = ?) then 1 else 0 end) as validees', [EvalSite::STATUT_VALIDEE])
            ->toBase()
            ->first());

        $nombre = fn ($n) => number_format((int) $n, 0, ',', ' ');
        $part = fn ($n) => $c['total'] ? round($n / $c['total'] * 100, 1).' %' : '—';

        return [
            Stat::make('Clients actifs', $nombre($c['total']))
                ->description('+'.$nombre($c['nouveaux']).' sur les 7 derniers jours')
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)
                ->icon(Heroicon::OutlinedUserGroup)
                ->color('primary'),
            Stat::make('KYC faits', $nombre($c['kyc']))
                ->description($part($c['kyc']).' des clients')
                ->icon(Heroicon::OutlinedIdentification)
                ->color('success'),
            Stat::make('KYC à faire', $nombre($c['total'] - $c['kyc']))
                ->description('Clients sans KYC')
                ->icon(Heroicon::OutlinedExclamationCircle)
                ->color('warning'),
            Stat::make('Dimensionnements', $nombre($c['evaluations']))
                ->description($nombre($c['validees']).' validé(s)')
                ->icon(Heroicon::OutlinedHomeModern)
                ->color('info'),
        ];
    }
}
