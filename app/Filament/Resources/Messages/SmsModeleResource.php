<?php

namespace App\Filament\Resources\Messages;

use App\Filament\Messages\EnvoiSms;
use App\Filament\Resources\Messages\Pages\CreerModeleSms;
use App\Filament\Resources\Messages\Pages\ListeModelesSms;
use App\Filament\Resources\Messages\Pages\ModifierModeleSms;
use App\Models\Client;
use App\Models\Entite;
use App\Models\SmsConfiguration;
use App\Models\SmsModele;
use App\Models\User;
use App\Sms\LeTexto;
use App\Support\Acces;
use App\Support\CacheReferences;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Messages > Modèles SMS. Circuit : le créateur enregistre (→ en attente), un approbateur (messages.valider)
 * approuve ou rejette. Seuls les modèles approuvés et actifs servent à envoyer des SMS.
 * Modifier le texte d'un modèle approuvé le renvoie en approbation.
 */
class SmsModeleResource extends Resource
{
    protected static ?string $model = SmsModele::class;

    protected static ?string $slug = 'messages/modeles';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Messages';

    protected static ?string $modelLabel = 'modèle SMS';

    protected static ?string $pluralModelLabel = 'modèles SMS';

    protected static string|\UnitEnum|null $navigationGroup = 'Messages';

    protected static ?int $navigationSort = 1;

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
        return SmsConfiguration::installe() && Acces::peut('messages.creer');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return Acces::peut('messages.modifier') || ($record->cree_par === auth()->id() && $record->approbation !== 'approuve');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false; // on désactive un modèle, on ne le supprime pas (historique)
    }

    public static function peutApprouver(SmsModele $modele): bool
    {
        // Un approbateur ne valide pas son propre modèle (sauf administrateur)
        return $modele->approbation === 'en_attente' && Acces::peut('messages.valider')
            && ($modele->cree_par !== auth()->id() || Acces::estAdministrateur());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Modèle')
                ->icon(Heroicon::OutlinedDocumentText)
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('nom')->label('Nom du modèle')->required()->maxLength(120),
                    Select::make('categorie')->label('Catégorie')->options(SmsModele::CATEGORIES)->required(),
                    Select::make('id_entite')->label('Entité')->options(fn () => Entite::tousLesLibelles())->placeholder('Toutes'),
                    Toggle::make('actif')->label('Actif')->default(true)->inline(false)
                        ->helperText('Un modèle inactif n\'est plus proposé à l\'envoi.'),
                ]),
            Section::make('Contenu du SMS')
                ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
                ->schema([
                    Textarea::make('contenu')
                        ->label('Message')
                        ->required()
                        ->rows(6)
                        ->maxLength(918) // 6 SMS
                        ->live(debounce: 400)
                        ->helperText(fn (?string $state) => new HtmlString(
                            '<b>'.e(LeTexto::compteur((string) $state)).'</b> (avant remplacement des variables) · Variables : '
                            .collect(LeTexto::VARIABLES)->map(fn ($aide, $v) => '<code title="'.e($aide).'">{'.$v.'}</code>')->implode(' '))),
                    TextEntry::make('apercu')
                        ->label('Aperçu (client fictif)')
                        ->state(function (Get $get) {
                            $client = new Client(['nom_client' => 'KONE', 'prenoms_client' => 'Adama', 'contact1_client' => '0701020304']);
                            $texte = LeTexto::rendre((string) $get('contenu'), null, [
                                'nom' => 'KONE', 'prenoms' => 'Adama', 'nom_complet' => 'KONE Adama', 'telephone' => '0701020304',
                                'num_contrat' => 'CT-2026-000123', 'num_contrat_upya' => '190423', 'produit' => 'KOMDOGO 855', 'montant' => '855 000 F',
                            ]);

                            return new HtmlString('<div class="gr-sms-bulle">'.nl2br(e($texte ?: '…')).'</div>');
                        }),
                ]),
            Section::make('Approbation')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->visibleOn('edit')
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('approbation')->label('État')
                        ->formatStateUsing(fn ($state) => SmsModele::APPROBATIONS[$state] ?? $state)->badge(),
                    TextEntry::make('approbateur.name')->label('Approuvé par')->placeholder('—'),
                    TextEntry::make('approuve_le')->label('Le')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('motif_rejet')->label('Motif du rejet')->placeholder('—')->columnSpanFull()
                        ->visible(fn (?SmsModele $record) => $record?->approbation === 'rejete'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['createur:id,name', 'approbateur:id,name'])->withCount('messages'))
            ->defaultSort('created_at', 'desc') // derniers modèles créés en haut
            ->searchPlaceholder('Rechercher…')
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
            ->columns([
                TextColumn::make('nom')->label('Nom du modèle')->weight('medium')->searchable()->sortable(),
                TextColumn::make('statut')
                    ->label('Statut')
                    ->state(fn (SmsModele $r) => match (true) {
                        $r->approbation === 'en_attente' => 'En attente d\'approbation',
                        $r->approbation === 'rejete' => 'Rejeté',
                        $r->approbation === 'brouillon' => 'Brouillon',
                        $r->actif => 'Actif',
                        default => 'Inactif',
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Actif' => 'success',
                        'En attente d\'approbation' => 'warning',
                        'Rejeté' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('approbation')
                    ->label('Approbation')
                    ->formatStateUsing(fn ($state) => SmsModele::APPROBATIONS[$state] ?? $state)
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'approuve' => 'success',
                        'en_attente' => 'info',
                        'rejete' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('application')->label('Application')->state('LeTexto')->badge()->color('success'),
                TextColumn::make('createur.name')->label('Créé par')->placeholder('—'),
                TextColumn::make('approbateur.name')->label('Approbateur')->placeholder('—'),
                TextColumn::make('id_entite')->label('Entité')
                    ->formatStateUsing(fn ($state) => CacheReferences::libelle(Entite::class, $state))
                    ->badge()->color('info')->placeholder('Toutes'),
                TextColumn::make('categorie')->label('Catégorie')
                    ->formatStateUsing(fn ($state) => SmsModele::CATEGORIES[$state] ?? $state)
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'facturation', 'rappel' => 'success',
                        'onboarding', 'installation' => 'info',
                        'relance', 'alerte_service' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('contenu')->label('Contenu')->limit(40)->tooltip(fn (SmsModele $r) => $r->contenu)->searchable(),
                TextColumn::make('variables')->label('Variables')
                    ->state(fn (SmsModele $r) => $r->variables() ?: null)
                    ->badge()->color('success')->placeholder('—'),
                TextColumn::make('messages_count')->label('Envois')->alignEnd()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('categorie')->label('Catégorie')->placeholder('Toutes')->options(SmsModele::CATEGORIES),
                SelectFilter::make('id_entite')->label('Entité')->placeholder('Toutes')->options(fn () => Entite::tousLesLibelles()),
                SelectFilter::make('approbation')->label('Approbation')->placeholder('Toutes')->options(SmsModele::APPROBATIONS),
                TernaryFilter::make('actif')->label('Statut')->placeholder('Tous')->trueLabel('Actifs')->falseLabel('Inactifs'),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->deferFilters(false)
            ->recordActions([
                Action::make('approuver')
                    ->label('Approuver')
                    ->tooltip('Approuver')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->iconButton()
                    ->color('success')
                    ->visible(fn (SmsModele $record) => self::peutApprouver($record))
                    ->requiresConfirmation()
                    ->modalHeading('Approuver ce modèle ?')
                    ->modalDescription(fn (SmsModele $record) => $record->contenu)
                    ->action(function (SmsModele $record) {
                        $record->update(['approbation' => 'approuve', 'approuve_par' => auth()->id(), 'approuve_le' => now(), 'motif_rejet' => null]);
                        self::notifierCreateur($record, 'Modèle SMS approuvé : '.$record->nom, 'success');
                        Notification::make()->title('Modèle approuvé')->success()->send();
                    }),
                Action::make('rejeter')
                    ->label('Rejeter')
                    ->tooltip('Rejeter')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->iconButton()
                    ->color('danger')
                    ->visible(fn (SmsModele $record) => self::peutApprouver($record))
                    ->schema([Textarea::make('motif_rejet')->label('Motif du rejet')->required()->rows(3)])
                    ->action(function (SmsModele $record, array $data) {
                        $record->update(['approbation' => 'rejete', 'approuve_par' => auth()->id(), 'approuve_le' => now(), 'motif_rejet' => $data['motif_rejet']]);
                        self::notifierCreateur($record, 'Modèle SMS rejeté : '.$record->nom, 'danger', $data['motif_rejet']);
                        Notification::make()->title('Modèle rejeté')->success()->send();
                    }),
                ActionGroup::make([
                    EnvoiSms::action('envoyer')
                        ->visible(fn (SmsModele $record) => EnvoiSms::peutEnvoyer() && $record->utilisable()),
                    EditAction::make(),
                    Action::make('soumettre')
                        ->label('Soumettre pour approbation')
                        ->icon(Heroicon::OutlinedPaperAirplane)
                        ->visible(fn (SmsModele $record) => in_array($record->approbation, ['brouillon', 'rejete'], true) && static::canEdit($record))
                        ->action(function (SmsModele $record) {
                            $record->update(['approbation' => 'en_attente', 'approuve_par' => null, 'approuve_le' => null]);
                            self::notifierApprobateurs($record);
                            Notification::make()->title('Modèle soumis pour approbation')->success()->send();
                        }),
                    Action::make('basculer')
                        ->label(fn (SmsModele $record) => $record->actif ? 'Désactiver' : 'Activer')
                        ->icon(fn (SmsModele $record) => $record->actif ? Heroicon::OutlinedPauseCircle : Heroicon::OutlinedPlayCircle)
                        ->visible(fn () => Acces::peut('messages.modifier'))
                        ->action(fn (SmsModele $record) => $record->update(['actif' => ! $record->actif])),
                    Action::make('dupliquer')
                        ->label('Dupliquer')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->visible(fn () => static::canCreate())
                        ->action(function (SmsModele $record) {
                            $copie = $record->replicate(['approuve_par', 'approuve_le', 'motif_rejet']);
                            $copie->fill(['nom' => $record->nom.' (copie)', 'approbation' => 'brouillon', 'cree_par' => auth()->id()])->save();
                            Notification::make()->title('Copie créée en brouillon')->success()->send();
                        }),
                ])->icon(Heroicon::EllipsisVertical),
            ])
            ->emptyStateIcon(Heroicon::OutlinedChatBubbleLeftRight)
            ->emptyStateHeading('Aucun modèle SMS')
            ->emptyStateDescription('Créez un modèle : il sera envoyé en approbation avant de pouvoir servir.');
    }

    /** Prévient les approbateurs (cloche) qu'un modèle attend leur validation. */
    public static function notifierApprobateurs(SmsModele $modele): void
    {
        rescue(function () use ($modele) {
            $approbateurs = User::query()->get()->filter(fn (User $u) => $u->getKey() !== auth()->id() && Acces::peut('messages.valider', $u));
            if ($approbateurs->isEmpty()) {
                return;
            }
            Notification::make()
                ->title('Modèle SMS à approuver : '.$modele->nom)
                ->body(\Illuminate\Support\Str::limit($modele->contenu, 120))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->warning()
                ->actions([Action::make('voir')->label('Voir')->url(static::getUrl('index', ['filters' => ['approbation' => ['value' => 'en_attente']]]))])
                ->sendToDatabase($approbateurs);
        }, null, false);
    }

    private static function notifierCreateur(SmsModele $modele, string $titre, string $ton, ?string $motif = null): void
    {
        rescue(function () use ($modele, $titre, $ton, $motif) {
            if (! $modele->createur || $modele->cree_par === auth()->id()) {
                return;
            }
            Notification::make()->title($titre)->body($motif)->{$ton}()->sendToDatabase($modele->createur);
        }, null, false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListeModelesSms::route('/'),
            'create' => CreerModeleSms::route('/create'),
            'edit' => ModifierModeleSms::route('/{record}/edit'),
        ];
    }
}
