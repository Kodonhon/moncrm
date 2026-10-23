<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\ReferenceResource;
use App\Filament\Clusters\Parametres\Resources\Pages\GererProduits;
use App\Models\Produit;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

class ProduitResource extends ReferenceResource
{
    protected static ?string $model = Produit::class;

    protected static ?string $slug = 'produits';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 11;

    protected static ?string $modelLabel = 'produit';

    protected static ?string $pluralModelLabel = 'produits';

    protected static function champsSupplementaires(): array
    {
        return [
            TextInput::make('desc_prod')->label('Code du kit')->placeholder('KIT-NIKLA')->maxLength(255),
            TextInput::make('montant_com_commer')
                ->label('Commission du commercial')
                ->numeric()
                ->minValue(0)
                ->suffix('FCFA')
                ->visible(fn () => Produit::commissionDisponible()),
            TextInput::make('debit_prod')->label('Débit')->numeric()->integer(),
        ];
    }

    protected static function colonnesSupplementaires(): array
    {
        return [
            TextColumn::make('desc_prod')->label('Code du kit')->badge()->color('gray')->copyable()->placeholder('—'),
            TextColumn::make('montant_com_commer')
                ->label('Commission')
                ->formatStateUsing(fn ($state) => filled($state) ? number_format((float) $state, 0, ',', ' ').' FCFA' : null)
                ->alignEnd()
                ->sortable()
                ->placeholder('—')
                ->visible(fn () => Produit::commissionDisponible()),
            TextColumn::make('debit_prod')->label('Débit')->numeric(locale: 'fr')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    protected static function pageGestion(): string
    {
        return GererProduits::class;
    }
}
