<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererTypesPiece;
use App\Models\TypePiece;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class TypePieceResource extends ReferenceResource
{
    protected static ?string $model = TypePiece::class;

    protected static ?string $slug = 'types-de-piece';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'type de pièce';

    protected static ?string $pluralModelLabel = 'types de pièce';

    protected static ?string $relationUtilisations = 'clients';

    protected static string $libelleUtilisations = 'Clients';

    protected static function pageGestion(): string
    {
        return GererTypesPiece::class;
    }
}
