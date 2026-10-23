<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererSourcesEnergie;
use App\Models\SourceEnergie;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class SourceEnergieResource extends ReferenceResource
{
    protected static ?string $model = SourceEnergie::class;

    protected static ?string $slug = 'sources-d-energie';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?int $navigationSort = 13;

    protected static ?string $modelLabel = 'source d\'énergie';

    protected static ?string $pluralModelLabel = 'sources d\'énergie';

    protected static function pageGestion(): string
    {
        return GererSourcesEnergie::class;
    }
}
