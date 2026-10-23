<?php

namespace App\Filament\Clusters\Parametres\Resources;

use App\Filament\Clusters\Parametres\Resources\Pages\GererFormulaireEvalSite;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Même éditeur que les formulaires client et KYC, appliqué à l'évaluation du site. */
class FormulaireEvalSiteResource extends FormulaireChampResource
{
    public const FORMULAIRE = 'eval_site';

    protected static ?string $slug = 'formulaire-evaluation-site';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Formulaire évaluation du site';

    protected static ?string $pluralModelLabel = 'questions de l\'évaluation du site';

    public static function getTitleCasePluralModelLabel(): string
    {
        return 'Formulaire évaluation du site';
    }

    public static function getPages(): array
    {
        return [
            'index' => GererFormulaireEvalSite::route('/'),
        ];
    }
}
