<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererSitesShops;
use App\Models\SiteShop;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

class SiteShopResource extends ReferenceResource
{
    protected static ?string $model = SiteShop::class;

    protected static ?string $slug = 'sites-shops';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'site / shop';

    protected static ?string $pluralModelLabel = 'sites & shops';

    protected static ?string $relationUtilisations = 'localites';

    protected static string $libelleUtilisations = 'Localités';

    protected static function champsSupplementaires(): array
    {
        return [
            TextInput::make('desc_site_shop')->label('Description')->maxLength(255),
            Textarea::make('comment_site_shop')->label('Commentaire')->rows(2),
        ];
    }

    protected static function colonnesSupplementaires(): array
    {
        return [
            TextColumn::make('desc_site_shop')->label('Description')->placeholder('—')->toggleable(),
        ];
    }

    protected static function pageGestion(): string
    {
        return GererSitesShops::class;
    }
}
