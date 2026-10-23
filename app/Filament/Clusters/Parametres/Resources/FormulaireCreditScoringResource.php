<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\Resources\Pages\GererFormulaireCreditScoring;
use App\Models\CreditScoring;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Même éditeur que les autres formulaires, appliqué au credit scoring (questions installées par crm:credit-scoring). */
class FormulaireCreditScoringResource extends FormulaireChampResource
{
    public const FORMULAIRE = 'credit_scoring';

    protected static ?string $slug = 'formulaire-credit-scoring';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Formulaire credit scoring';

    protected static ?string $pluralModelLabel = 'questions du credit scoring';

    public static function getTitleCasePluralModelLabel(): string
    {
        return 'Formulaire credit scoring';
    }

    public static function canAccess(): bool
    {
        return CreditScoring::tableDisponible() && parent::canAccess();
    }

    public static function getPages(): array
    {
        return [
            'index' => GererFormulaireCreditScoring::route('/'),
        ];
    }
}
