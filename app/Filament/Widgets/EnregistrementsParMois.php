<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use Filament\Widgets\ChartWidget;

class EnregistrementsParMois extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Enregistrements par mois (12 derniers mois)';

    protected ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $debut = now()->subMonths(11)->startOfMonth();

        $parJour = Client::query()
            ->where('date_client', '>=', $debut->toDateString())
            ->selectRaw('date_client, count(*) as total')
            ->groupBy('date_client')
            ->toBase()
            ->get();

        $parMois = $parJour->groupBy(fn ($ligne) => substr((string) $ligne->date_client, 0, 7))->map->sum('total');

        $mois = collect(range(0, 11))->map(fn ($i) => $debut->copy()->addMonths($i));

        return [
            'datasets' => [[
                'label' => 'Clients enregistrés',
                'data' => $mois->map(fn ($m) => (int) ($parMois[$m->format('Y-m')] ?? 0))->all(),
                'borderColor' => '#3E3765', // violet GREENO (charte)
                'backgroundColor' => 'rgba(90, 170, 60, 0.14)', // vert GREENO pâle
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $mois->map(fn ($m) => $m->translatedFormat('M y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
