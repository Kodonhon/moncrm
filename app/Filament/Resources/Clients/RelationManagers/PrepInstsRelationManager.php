<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Formulaires\FormulairePrepInst;
use App\Models\Agent;
use App\Models\Client;
use App\Models\PrepInst;
use App\Models\Produit;
use App\Support\CacheReferences;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Appels du CX pour la préparation de l'installation (un par appel ; le plus récent donne l'état). */
class PrepInstsRelationManager extends RelationManager
{
    protected static string $relationship = 'prepInsts';

    protected static ?string $title = 'Préparation installation';

    protected static ?string $modelLabel = 'appel CX';

    protected static ?string $pluralModelLabel = 'appels CX';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedWrenchScrewdriver;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return \App\Support\Acces::peut('preparation_installation.voir') && $pageClass === ViewClient::class && PrepInst::tableDisponible();
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->prepInsts()->count();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id_prep')
            ->defaultSort('date_prep', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->orderByDesc('heure_prep'))
            ->paginated([10, 25, 'all'])
            ->columns([
                TextColumn::make('date_prep')
                    ->label('Date')
                    ->date('d M Y'),
                TextColumn::make('heure_prep')
                    ->label('Heure')
                    ->formatStateUsing(fn ($state) => $state ? substr((string) $state, 0, 5) : null)
                    ->placeholder('—'),
                TextColumn::make('id_agent_traitant')
                    ->label('Agent CX')
                    ->formatStateUsing(fn (PrepInst $record) => CacheReferences::libelle(Agent::class, $record->id_agent_traitant) ?? ($record->id_agent_traitant ? 'Agent '.$record->id_agent_traitant : null))
                    ->placeholder('—'),
                TextColumn::make('resul_prep_inst')
                    ->label('Résultat')
                    ->state(fn (PrepInst $record) => $record->libelleResultat())
                    ->badge()
                    ->color(fn (PrepInst $record) => PrepInst::ETATS[PrepInst::etat($record->resul_prep_inst)][1])
                    ->placeholder('—'),
                TextColumn::make('si_pas_pret_raison')
                    ->label('Raison (pas prêt)')
                    ->limit(35)
                    ->tooltip(fn (PrepInst $record) => $record->si_pas_pret_raison)
                    ->placeholder('—'),
                TextColumn::make('id_produit')
                    ->label('Pompe')
                    ->formatStateUsing(fn ($state) => CacheReferences::libelle(Produit::class, $state))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('id_src_eau')
                    ->label('Source d\'eau')
                    ->formatStateUsing(fn ($state) => PrepInst::SOURCES_EAU[(int) $state] ?? null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('commentaire_prepa_inst')
                    ->label('Commentaire')
                    ->limit(80)
                    ->tooltip(fn (PrepInst $record) => $record->commentaire_prepa_inst)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('nouvelAppelCx')
            ->authorize(fn () => \App\Support\Acces::peut('preparation_installation.creer'))
                    ->label('Nouvel appel CX')
                    ->icon(Heroicon::OutlinedPhone)
                    ->modalHeading(fn () => 'Préparation installation · '.$this->getOwnerRecord()->nom_complet)
                    ->modalSubmitActionLabel('Enregistrer l\'appel')
                    ->modalWidth('4xl')
                    ->slideOver()
                    ->schema(FormulairePrepInst::schema())
                    ->fillForm(fn () => FormulairePrepInst::valeursParDefaut($this->getOwnerRecord()))
                    ->action(function (array $data) {
                        /** @var Client $client */
                        $client = $this->getOwnerRecord();
                        $appel = FormulairePrepInst::enregistrer($client, $data);
                        $this->dispatch(ViewClient::EVENEMENT_MODIFIEE);
                        Notification::make()->title('Appel enregistré : '.$appel->libelleResultat())->success()->send();
                    }),
            ])
            ->emptyStateHeading('Aucun appel CX')
            ->emptyStateDescription('Après la validation au credit scoring, le CX appelle le client pour préparer l\'installation.')
            ->emptyStateIcon(Heroicon::OutlinedWrenchScrewdriver);
    }
}
