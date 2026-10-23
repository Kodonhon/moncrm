<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererSourcesClient;
use App\Models\SourceClient;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class SourceClientResource extends ReferenceResource
{
    protected static ?string $model = SourceClient::class;

    protected static ?string $slug = 'sources';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsPointingIn;

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'source';

    protected static ?string $pluralModelLabel = 'sources client';

    protected static ?string $relationUtilisations = 'canaux';

    protected static string $libelleUtilisations = 'Canaux';

    protected static function pageGestion(): string
    {
        return GererSourcesClient::class;
    }
}
