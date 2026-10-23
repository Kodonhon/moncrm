<?php

namespace App\Filament\Resources\Installations\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Quatre cartes en tête de la liste des installations : rapports, installés, non installés, taux de réussite. */
class IndicateursInstallations extends Widget
{
    protected string $view = 'filament.installations.indicateurs';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $c = Cache::remember('indicateurs-installations', now()->addMinutes(5), fn () => (array) DB::table('installation')->selectRaw('
            count(*) as total,
            sum(case when systeme_installe = 1 then 1 else 0 end) as installes,
            sum(case when systeme_installe = 0 then 1 else 0 end) as non_installes,
            count(distinct case when systeme_installe = 1 then id_client end) as clients_installes
        ')->first());

        $total = (int) $c['total'];
        $installes = (int) $c['installes'];

        return [
            'total' => $total,
            'installes' => $installes,
            'nonInstalles' => (int) $c['non_installes'],
            'clientsInstalles' => (int) $c['clients_installes'],
            'taux' => $total ? round($installes / $total * 100, 1) : 0,
        ];
    }
}
