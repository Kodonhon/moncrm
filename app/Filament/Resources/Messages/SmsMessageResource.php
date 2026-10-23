<?php

namespace App\Filament\Resources\Messages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Messages\Pages\ListeMessagesSms;
use App\Models\SmsConfiguration;
use App\Models\SmsMessage;
use App\Models\SmsModele;
use App\Sms\LeTexto;
use App\Support\Acces;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Messages > Historique des envois : chaque SMS envoyé, simulé ou en échec, avec la réponse de l'API. */
class SmsMessageResource extends Resource
{
    protected static ?string $model = SmsMessage::class;

    protected static ?string $slug = 'messages/historique';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Historique des envois';

    protected static ?string $modelLabel = 'SMS';

    protected static ?string $pluralModelLabel = 'SMS envoyés';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return SmsConfiguration::installe() && Acces::peut('messages.voir');
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['modele:id,nom', 'client:id_client,nom_client,prenoms_client', 'expediteur:id,name']))
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->searchPlaceholder('Rechercher un numéro, un texte…')
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('telephone')->label('Téléphone')->copyable()->searchable(),
                TextColumn::make('client.nom_complet')->label('Client')
                    ->url(fn (SmsMessage $r) => $r->client ? ClientResource::getUrl('view', ['record' => $r->client]) : null)
                    ->placeholder('—'),
                TextColumn::make('modele.nom')->label('Modèle')->badge()->color('gray')->placeholder('Message libre'),
                TextColumn::make('contenu')->label('Message')->limit(50)->tooltip(fn (SmsMessage $r) => $r->contenu)->searchable(),
                TextColumn::make('nb_sms')->label('SMS')->alignEnd(),
                TextColumn::make('statut')->label('Statut')
                    ->formatStateUsing(fn ($state) => SmsMessage::STATUTS[$state] ?? $state)
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'envoye' => 'success',
                        'echec' => 'danger',
                        'simule' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('expediteur.name')->label('Envoyé par')->placeholder('Automatique'),
                TextColumn::make('code_http')->label('Code API')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('statut')->label('Statut')->placeholder('Tous')->options(SmsMessage::STATUTS),
                SelectFilter::make('sms_modele_id')->label('Modèle')->placeholder('Tous')
                    ->options(fn () => SmsModele::query()->orderBy('nom')->pluck('nom', 'id')->all())->searchable(),
                Filter::make('periode')
                    ->schema([
                        DatePicker::make('du')->label('Du')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('au')->label('Au')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->columns(2)
                    ->columnSpan(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['du'] ?? null, fn (Builder $q, $du) => $q->whereDate('created_at', '>=', $du))
                        ->when($data['au'] ?? null, fn (Builder $q, $au) => $q->whereDate('created_at', '<=', $au))),
                Filter::make('lot')
                    ->schema([TextInput::make('value')->label('Envoi groupé (n° de lot)')])
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->where('lot', $data['value']) : $query)
                    ->indicateUsing(fn (array $data) => filled($data['value'] ?? null) ? 'Un seul envoi groupé' : null),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(5)
            ->deferFilters(false)
            ->recordActions([
                Action::make('details')
                    ->label('Détails')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->modalHeading('Détail du SMS')
                    ->modalSubmitAction(false)
                    ->schema([
                        TextEntry::make('contenu')->label('Message'),
                        TextEntry::make('reponse_api')->label('Réponse de l\'API')->placeholder('—')->fontFamily('mono'),
                    ]),
                Action::make('renvoyer')
                    ->label('Renvoyer')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->iconButton()
                    ->color('warning')
                    ->visible(fn (SmsMessage $r) => in_array($r->statut, ['echec', 'simule'], true) && Acces::peut('messages.envoyer'))
                    ->requiresConfirmation()
                    ->action(function (SmsMessage $r) {
                        $nouveau = LeTexto::envoyer($r->client ?? $r->telephone, $r->client ? ($r->modele?->contenu ?? $r->contenu) : $r->contenu, $r->modele);
                        Notification::make()->title('SMS : '.(SmsMessage::STATUTS[$nouveau->statut] ?? $nouveau->statut))
                            ->{$nouveau->statut === 'echec' ? 'danger' : 'success'}()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedChatBubbleLeftRight)
            ->emptyStateHeading('Aucun SMS envoyé');
    }

    public static function getPages(): array
    {
        return ['index' => ListeMessagesSms::route('/')];
    }
}
