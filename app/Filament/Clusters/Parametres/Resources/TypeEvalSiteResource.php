<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererTypesEvalSite;
use App\Models\Entite;
use App\Models\TypeEvalSite;
use App\Support\CacheReferences;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

class TypeEvalSiteResource extends ReferenceResource
{
    protected static ?string $model = TypeEvalSite::class;

    protected static ?string $slug = 'types-d-evaluation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 14;

    protected static ?string $modelLabel = 'type d\'évaluation';

    protected static ?string $pluralModelLabel = 'types d\'évaluation';

    protected static function champsSupplementaires(): array
    {
        return [
            Select::make('id_entite')
                ->label('Entité')
                ->options(fn (?TypeEvalSite $record) => Entite::options($record?->id_entite))
                ->default(0)
                ->required()
                ->helperText('Proposé seulement aux clients de cette entité. « All » : proposé à tous les clients.'),
        ];
    }

    protected static function colonnesSupplementaires(): array
    {
        return [
            TextColumn::make('id_entite')
                ->label('Entité')
                ->formatStateUsing(fn ($state) => CacheReferences::libelle(Entite::class, $state) ?? 'All')
                ->badge()
                ->color('gray'),
        ];
    }

    protected static function pageGestion(): string
    {
        return GererTypesEvalSite::class;
    }
}
