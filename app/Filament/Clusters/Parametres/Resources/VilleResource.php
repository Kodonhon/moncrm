<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererVilles;
use App\Models\Ville;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class VilleResource extends ReferenceResource
{
    protected static ?string $model = Ville::class;

    protected static ?string $slug = 'villes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'ville';

    protected static ?string $pluralModelLabel = 'villes';

    protected static ?string $relationUtilisations = 'localites';

    protected static string $libelleUtilisations = 'Localités';

    protected static function pageGestion(): string
    {
        return GererVilles::class;
    }
}
