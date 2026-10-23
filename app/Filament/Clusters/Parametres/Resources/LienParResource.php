<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererLiensPar;
use App\Models\LienPar;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class LienParResource extends ReferenceResource
{
    protected static ?string $model = LienPar::class;

    protected static ?string $slug = 'liens-de-parente';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'lien de parenté';

    protected static ?string $pluralModelLabel = 'liens de parenté';

    protected static ?string $relationUtilisations = 'kycs';

    protected static string $libelleUtilisations = 'KYC';

    protected static function pageGestion(): string
    {
        return GererLiensPar::class;
    }
}
