<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Installations\InstallationResource;
use App\Models\Installation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Rapports d'installation du client (mêmes colonnes et même rapport que l'écran Installations). */
class InstallationsRelationManager extends RelationManager
{
    protected static string $relationship = 'installations';

    protected static ?string $title = 'Installations';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedWrenchScrewdriver;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewClient::class && Installation::tableDisponible() && \App\Support\Acces::peut('installations.voir');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->installations()->count();
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return $ownerRecord->installations()->where('systeme_installe', 1)->exists() ? 'success' : 'gray';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id_installation')
            ->defaultSort('date_installation', 'desc')
            ->paginated(false)
            ->recordAction('voirRapport')
            ->columns(InstallationResource::colonnes(avecClient: false))
            ->recordActions([InstallationResource::actionVoir()])
            ->emptyStateIcon(Heroicon::OutlinedWrenchScrewdriver)
            ->emptyStateHeading('Aucun rapport d\'installation')
            ->emptyStateDescription('Le rapport du technicien apparaîtra ici après l\'installation.');
    }
}
