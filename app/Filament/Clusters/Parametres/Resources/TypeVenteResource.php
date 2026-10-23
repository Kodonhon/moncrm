<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererTypesVente;
use App\Models\TypeVente;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class TypeVenteResource extends ReferenceResource
{
    protected static ?string $model = TypeVente::class;

    protected static ?string $slug = 'types-de-vente';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 9;

    protected static ?string $modelLabel = 'type de vente';

    protected static ?string $pluralModelLabel = 'types de vente';

    protected static ?string $relationUtilisations = 'kycs';

    protected static string $libelleUtilisations = 'KYC';

    protected static function pageGestion(): string
    {
        return GererTypesVente::class;
    }
}
