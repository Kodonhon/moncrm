<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererUsagesKit;
use App\Models\UsageKit;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class UsageKitResource extends ReferenceResource
{
    protected static ?string $model = UsageKit::class;

    protected static ?string $slug = 'usages-du-kit';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 12;

    protected static ?string $modelLabel = 'usage du kit';

    protected static ?string $pluralModelLabel = 'usages du kit';

    protected static function pageGestion(): string
    {
        return GererUsagesKit::class;
    }
}
