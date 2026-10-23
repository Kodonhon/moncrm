<?php

namespace App\Filament\Resources\Deposits\Widgets;

use App\Models\PaiementContrat;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

/** Quatre cartes en tête de Deposit : contrats, total payé, dépôts du mois, dépôts partiels. */
class IndicateursDeposits extends Widget
{
    protected string $view = 'filament.deposits.indicateurs';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return Cache::remember('indicateurs-deposits', now()->addMinutes(5), fn () => (array) PaiementContrat::query()->toBase()->selectRaw("
            count(*) as contrats,
            coalesce(sum(total_paye), 0) as total_paye,
            count(*) filter (where date_deposit >= date_trunc('month', current_date)) as contrats_mois,
            coalesce(sum(mt_deposit) filter (where date_deposit >= date_trunc('month', current_date)), 0) as depots_mois,
            count(*) filter (where statut_amt = 'partial_deposit') as partiels
        ")->first());
    }
}
