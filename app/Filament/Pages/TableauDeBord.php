<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Support\TableauDeBordDonnees;
use Filament\Pages\Dashboard;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * Tableau de bord façon « TTP CRM » : salutation, carte du portefeuille, nouveaux clients par mois,
 * activité récente, parcours par étape, sources, installations et relances du CX.
 */
class TableauDeBord extends Dashboard
{
    protected static string $routePath = '/tableau-de-bord';

    /** En premier dans les menus (rail de gauche et menu du haut). */
    protected static ?int $navigationSort = -10;

    public static function canAccess(): bool
    {
        return \App\Support\Acces::peut('tableau_de_bord.voir');
    }

    /** Le titre est dans la page elle-même (« Bonjour, … »). */
    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getWidgets(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        $prenom = trim(strtok((string) (Auth::user()?->name ?? ''), ' ')) ?: 'à vous';

        return $schema->components([
            View::make('filament.pages.tableau-de-bord')->viewData([
                'd' => TableauDeBordDonnees::calculer(),
                'prenom' => mb_convert_case(mb_strtolower($prenom), MB_CASE_TITLE),
                'lienNouveauClient' => ClientResource::canCreate() ? ClientResource::getUrl('create') : null,
                'lienClients' => ClientResource::getUrl(),
            ]),
        ]);
    }
}
