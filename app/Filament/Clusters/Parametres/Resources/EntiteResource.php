<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererEntites;
use App\Models\Entite;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

class EntiteResource extends ReferenceResource
{
    protected static ?string $model = Entite::class;

    protected static ?string $slug = 'entites';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'entité';

    protected static ?string $pluralModelLabel = 'entités';

    protected static ?string $relationUtilisations = 'clients';

    protected static string $libelleUtilisations = 'Clients';

    protected static function champsSupplementaires(): array
    {
        return [
            TextInput::make('desc_entite')->label('Description')->maxLength(255),
        ];
    }

    protected static function colonnesSupplementaires(): array
    {
        return [
            TextColumn::make('desc_entite')->label('Description')->placeholder('—'),
        ];
    }

    protected static function pageGestion(): string
    {
        return GererEntites::class;
    }
}
