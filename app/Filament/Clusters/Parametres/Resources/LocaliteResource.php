<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererLocalites;
use App\Models\Localite;
use App\Models\SiteShop;
use App\Models\Ville;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

class LocaliteResource extends ReferenceResource
{
    protected static ?string $model = Localite::class;

    protected static ?string $slug = 'localites';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'localité';

    protected static ?string $pluralModelLabel = 'localités';

    protected static ?string $relationUtilisations = 'clients';

    protected static string $libelleUtilisations = 'Clients';

    protected static function champsSupplementaires(): array
    {
        return [
            Select::make('id_ville')
                ->label('Ville')
                ->options(fn (?Localite $record) => Ville::options($record?->id_ville))
                ->searchable()
                ->required(),
            Select::make('id_site_shop')
                ->label('Site / shop')
                ->options(fn (?Localite $record) => SiteShop::options($record?->id_site_shop))
                ->required(),
            TextInput::make('gps_localite')
                ->label('Coordonnées GPS')
                ->placeholder('5.3531211,-4.0222008')
                ->regex('/^-?\d{1,2}\.\d+,\s*-?\d{1,3}\.\d+$/')
                ->validationMessages(['regex' => 'Format attendu : latitude,longitude (ex. 5.3531211,-4.0222008).']),
        ];
    }

    protected static function colonnesSupplementaires(): array
    {
        return [
            TextColumn::make('ville.lib_ville')->label('Ville')->sortable(),
            TextColumn::make('siteShop.lib_site_shop')->label('Site / shop')->badge()->color('gray'),
            TextColumn::make('gps_localite')
                ->label('GPS')
                ->placeholder('—')
                ->url(fn (Localite $record) => $record->gps_localite ? 'https://www.google.com/maps?q='.$record->gps_localite : null, shouldOpenInNewTab: true)
                ->toggleable(),
        ];
    }

    protected static function filtresSupplementaires(): array
    {
        return [
            SelectFilter::make('id_ville')->label('Ville')->relationship('ville', 'lib_ville')->searchable()->preload(),
            SelectFilter::make('id_site_shop')->label('Site / shop')->relationship('siteShop', 'lib_site_shop'),
        ];
    }

    protected static function pageGestion(): string
    {
        return GererLocalites::class;
    }
}
