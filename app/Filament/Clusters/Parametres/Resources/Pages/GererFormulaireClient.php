<?php

namespace App\Filament\Clusters\Parametres\Resources\Pages;

use App\Filament\Clusters\Parametres\Resources\FormulaireChampResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\FormulaireChamp;
use App\Models\FormulaireSection;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GererFormulaireClient extends ManageRecords
{
    protected static string $resource = FormulaireChampResource::class;

    protected ?string $subheading = 'Glissez les lignes pour changer l\'ordre des questions. Les modifications s\'appliquent immédiatement.';

    protected function formulaire(): string
    {
        return static::$resource::FORMULAIRE;
    }

    /** URL où voir le formulaire tel que l'utilisateur le remplit (null : pas d'aperçu direct). */
    protected function urlApercu(): ?string
    {
        return ClientResource::getUrl('create');
    }

    /** Sections du formulaire : ordre (glisser-déposer), nom, description, affichage et conditions. */
    protected function actionSections(): Action
    {
        $formulaire = $this->formulaire();
        $ressource = static::$resource;

        return Action::make('sections')
            ->label('Sections')
            ->icon(Heroicon::OutlinedRectangleGroup)
            ->color('gray')
            ->modalHeading('Sections du formulaire')
            ->modalDescription('Glissez pour changer l\'ordre. Une section masquée ou dont les conditions sont fausses n\'apparaît pas (ses questions non plus).')
            ->modalWidth('4xl')
            ->slideOver()
            ->fillForm(fn () => ['sections' => FormulaireSection::query()
                ->where('formulaire', $formulaire)
                ->orderBy('ordre')
                ->get()
                ->map(fn (FormulaireSection $section) => [
                    'id' => $section->id,
                    'nom' => $section->nom,
                    'description' => $section->description,
                    'visible' => $section->visible,
                    'conditions' => $section->conditions ?? [],
                    'questions' => FormulaireChamp::pour($formulaire)->where('section', $section->nom)->count(),
                ])
                ->all()])
            ->schema([
                Repeater::make('sections')
                    ->hiddenLabel()
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn (array $state) => ($state['nom'] ?? 'Nouvelle section')
                        .(isset($state['questions']) ? ' · '.$state['questions'].' question'.($state['questions'] > 1 ? 's' : '') : ''))
                    ->deletable(false)
                    ->addActionLabel('Ajouter une section')
                    ->schema([
                        Hidden::make('id'),
                        Hidden::make('questions'),
                        TextInput::make('nom')->label('Nom')->required()->maxLength(100)->distinct(),
                        TextInput::make('description')->label('Description')->maxLength(255),
                        Toggle::make('visible')->label('Affichée')->default(true),
                        Repeater::make('conditions')
                            ->label('Conditions d\'affichage')
                            ->helperText('Toutes doivent être vraies. Sans condition : toujours affichée.')
                            ->schema([
                                Select::make('champ')->label('Si la question')->options(fn () => $ressource::autresQuestions(null))->required()->live(),
                                Select::make('operateur')->label('')->options(FormulaireChamp::OPERATEURS)->default('egal')->required()->live(),
                                Select::make('valeurs')
                                    ->label('Valeur(s)')
                                    ->multiple()
                                    ->options(fn (Get $get) => $ressource::optionsQuestion($get('champ')) ?? [])
                                    ->visible(fn (Get $get) => in_array($get('operateur'), ['egal', 'different'], true) && $ressource::optionsQuestion($get('champ')) !== null)
                                    ->required(fn (Get $get) => in_array($get('operateur'), ['egal', 'different'], true)),
                                TagsInput::make('valeurs')
                                    ->label('Valeur(s)')
                                    ->visible(fn (Get $get) => in_array($get('operateur'), ['egal', 'different'], true) && $ressource::optionsQuestion($get('champ')) === null)
                                    ->required(fn (Get $get) => in_array($get('operateur'), ['egal', 'different'], true)),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Ajouter une condition'),
                    ]),
            ])
            ->action(function (array $data) use ($formulaire) {
                foreach (array_values($data['sections'] ?? []) as $index => $ligne) {
                    $valeurs = [
                        'formulaire' => $formulaire,
                        'nom' => $ligne['nom'],
                        'description' => $ligne['description'] ?? null,
                        'visible' => (bool) ($ligne['visible'] ?? true),
                        'conditions' => array_values($ligne['conditions'] ?? []) ?: null,
                        'ordre' => ($index + 1) * 10,
                    ];

                    filled($ligne['id'] ?? null)
                        ? FormulaireSection::find($ligne['id'])?->update($valeurs)
                        : FormulaireSection::create($valeurs);
                }

                Notification::make()->title('Sections enregistrées')->success()->send();
            });
    }

    /** Le réordonnancement est une mise à jour groupée : on vide le cache du formulaire à la main. */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        parent::reorderTable($order, $draggedRecordKey);

        Cache::forget(FormulaireChamp::cleCache($this->formulaire()));
    }

    protected function getHeaderActions(): array
    {
        $formulaire = $this->formulaire();

        return [
            $this->actionSections(),
            Action::make('apercu')
                ->label('Voir le formulaire')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url($this->urlApercu(), shouldOpenInNewTab: true)
                ->visible(filled($this->urlApercu())),
            CreateAction::make()
                ->label('Ajouter une question')
                ->icon(Heroicon::OutlinedPlus)
                ->modalWidth('3xl')
                ->mutateDataUsing(function (array $data) use ($formulaire) {
                    $base = 'q_'.Str::slug($data['libelle'], '_');
                    $cle = $base;
                    for ($i = 2; FormulaireChamp::where('formulaire', $formulaire)->where('cle', $cle)->exists(); $i++) {
                        $cle = "{$base}_{$i}";
                    }

                    return $data + [
                        'formulaire' => $formulaire,
                        'cle' => $cle,
                        'standard' => false,
                        'stockage' => 'perso',
                        'ordre' => (int) FormulaireChamp::where('formulaire', $formulaire)->max('ordre') + 10,
                    ];
                }),
        ];
    }
}
