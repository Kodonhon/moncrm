<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererGenres;
use App\Models\Genre;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class GenreResource extends ReferenceResource
{
    protected static ?string $model = Genre::class;

    protected static ?string $slug = 'genres';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUser;

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'genre';

    protected static ?string $pluralModelLabel = 'genres';

    protected static ?string $relationUtilisations = 'clients';

    protected static string $libelleUtilisations = 'Clients';

    protected static function pageGestion(): string
    {
        return GererGenres::class;
    }
}
