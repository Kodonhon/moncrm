<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use Filament\Widgets\ChartWidget;

class ClientsParShop extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Clients par site / shop';

    protected ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $lignes = Client::query()
            ->leftJoin('localite_commune', 'localite_commune.id_localite_commune', '=', 'client.id_localite_commune')
            ->leftJoin('site_shop', 'site_shop.id_site_shop', '=', 'localite_commune.id_site_shop')
            ->where(fn ($q) => $q->whereNull('visibilite_client')->orWhere('visibilite_client', '<>', 0))
            ->selectRaw("coalesce(nullif(site_shop.lib_site_shop, 'Vide'), 'Non rattaché') as shop, count(*) as total")
            ->groupBy('shop')
            ->orderByDesc('total')
            ->toBase()
            ->get();

        return [
            'datasets' => [[
                'label' => 'Clients',
                'data' => $lignes->pluck('total')->map(fn ($n) => (int) $n)->all(),
                'backgroundColor' => '#5AAA3C', // vert GREENO (charte)
                'borderRadius' => 6,
            ]],
            'labels' => $lignes->pluck('shop')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
