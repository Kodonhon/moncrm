<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\Resources\Pages\GererFormulaireKyc;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Même éditeur que le formulaire client, appliqué aux questions du KYC. */
class FormulaireKycResource extends FormulaireChampResource
{
    public const FORMULAIRE = 'kyc';

    protected static ?string $slug = 'formulaire-kyc';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Formulaire KYC';

    protected static ?string $pluralModelLabel = 'questions du formulaire KYC';

    public static function getTitleCasePluralModelLabel(): string
    {
        return 'Formulaire KYC';
    }

    public static function getPages(): array
    {
        return [
            'index' => GererFormulaireKyc::route('/'),
        ];
    }
}
