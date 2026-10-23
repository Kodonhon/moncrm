<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ContratsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\CreditScoringsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\EvalSitesRelationManager;
use App\Filament\Resources\Clients\Schemas\ClientInfolist;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ClientResource extends Resource
{
    use \App\Filament\Concerns\ControleAcces;

    protected static string $moduleAcces = 'clients';

    protected static ?string $model = Client::class;

    protected static ?string $slug = 'clients';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 1;

    protected static string|\UnitEnum|null $navigationGroup = 'Ventes';

    protected static ?string $modelLabel = 'client';

    protected static ?string $pluralModelLabel = 'clients';

    protected static ?string $navigationLabel = 'Clients';

    protected static ?string $recordTitleAttribute = 'nom_client';

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ClientInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    /** Sous la fiche : dimensionnements (terrain), credit scoring (crédit), contrats. */
    public static function getRelations(): array
    {
        return [
            EvalSitesRelationManager::class,
            ContratsRelationManager::class,
            CreditScoringsRelationManager::class,
            \App\Filament\Resources\Clients\RelationManagers\PrepInstsRelationManager::class,
            \App\Filament\Resources\Clients\RelationManagers\InstallationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record?->nom_complet ?? static::getModelLabel();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['id_client', 'nom_client', 'prenoms_client', 'contact1_client', 'contact2_client'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->nom_complet;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'N°' => $record->id_client,
            'Tél.' => ClientsTable::formaterTelephone($record->contact1_client),
            'Localité' => $record->localite?->lib_localite,
        ]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('localite');
    }
}
