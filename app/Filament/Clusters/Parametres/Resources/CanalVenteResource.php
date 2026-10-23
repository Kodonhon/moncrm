<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererCanauxVente;
use App\Models\CanalVente;
use App\Models\Entite;
use App\Models\SourceClient;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

class CanalVenteResource extends ReferenceResource
{
    protected static ?string $model = CanalVente::class;

    protected static ?string $slug = 'canaux-de-vente';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'canal de vente';

    protected static ?string $pluralModelLabel = 'canaux de vente';

    protected static ?string $relationUtilisations = 'clients';

    protected static string $libelleUtilisations = 'Clients';

    protected static function champsSupplementaires(): array
    {
        return [
            Select::make('id_src_client')
                ->label('Source')
                ->options(fn (?CanalVente $record) => SourceClient::options($record?->id_src_client))
                ->required(),
            Select::make('id_entite')
                ->label('Entité')
                ->options(fn (?CanalVente $record) => Entite::options($record?->id_entite))
                ->required(),
        ];
    }

    protected static function colonnesSupplementaires(): array
    {
        return [
            TextColumn::make('source.lib_src_client')->label('Source')->badge()->color('gray'),
            TextColumn::make('entite.lib_entite')->label('Entité'),
        ];
    }

    protected static function filtresSupplementaires(): array
    {
        return [
            SelectFilter::make('id_src_client')->label('Source')->relationship('source', 'lib_src_client'),
            SelectFilter::make('id_entite')->label('Entité')->relationship('entite', 'lib_entite'),
        ];
    }

    protected static function pageGestion(): string
    {
        return GererCanauxVente::class;
    }
}
