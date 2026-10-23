<?php

namespace App\Formulaires;

use App\Jobs\EnvoyerPhotoDrive;
use App\Models\FormulaireChamp;
use App\Models\FormulaireSection;
use App\Photos\ParametresPhotos;
use Filament\Forms\Components\BaseFileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Construit un formulaire Filament à partir de sa configuration (table formulaire_champ) :
 * ordre et sections, questions masquées, conditions d'affichage, listes filtrées par une autre réponse.
 */
class ConstructeurFormulaire
{
    /** @var Collection<int, FormulaireChamp> */
    private Collection $champs;

    /** @var Collection<string, FormulaireSection> */
    private Collection $configSections;

    /**
     * @param  Model|null  $client  client concerné : ses informations reprises (« client.colonne ») font foi,
     *                              même si l'état du formulaire a été modifié côté navigateur
     */
    public function __construct(private string $formulaire, private ?Model $client = null)
    {
        $this->champs = FormulaireChamp::pour($formulaire)->keyBy('cle');
        $this->configSections = FormulaireSection::pour($formulaire);
    }

    /**
     * Sections dans l'ordre configuré (Paramètres > Sections), sinon dans l'ordre de leur première question.
     * Une section masquée disparaît ; une section conditionnelle n'apparaît que si ses conditions sont vraies.
     */
    public function sections(): array
    {
        return $this->champs
            ->filter(fn (FormulaireChamp $champ) => $champ->visible || $champ->verrouille)
            ->groupBy('section')
            ->reject(fn (Collection $champs, string $nom) => $this->configSections->has($nom) && ! $this->configSections[$nom]->visible)
            ->sortBy(fn (Collection $champs, string $nom) => $this->configSections[$nom]->ordre ?? $champs->min('ordre'))
            ->map(function (Collection $champs, string $nom) {
                $config = $this->configSections->get($nom);
                $section = Section::make($nom)
                    ->description($config?->description)
                    ->columns(2)
                    ->schema($champs->map(fn (FormulaireChamp $champ) => $this->composant($champ))->all());

                if ($conditions = $config?->conditions) {
                    $section->visible(fn (Get $get) => $this->conditionsRemplies($conditions, $get));
                }

                return $section;
            })
            ->values()
            ->all();
    }

    /**
     * Valeurs par défaut configurées dans Paramètres, [chemin => valeur].
     * Utile quand le formulaire est prérempli à la main (fillForm), ce qui ignore les défauts des champs.
     */
    public function valeursParDefaut(?Model $client = null): array
    {
        $valeurs = $this->champs
            ->filter(fn (FormulaireChamp $champ) => filled($champ->defaut))
            ->mapWithKeys(fn (FormulaireChamp $champ) => [$champ->cheminEtat() => $this->valeurParDefaut($champ, $client)])
            ->all();

        return Arr::undot($this->completerChoixUniques($valeurs));
    }

    /**
     * Valeur par défaut d'une question : « aujourdhui » pour une date, « client.colonne » pour reprendre
     * une information de la fiche du client (ex. client.entite_id_client), sinon la valeur saisie.
     */
    private function valeurParDefaut(FormulaireChamp $champ, ?Model $client = null): mixed
    {
        return match (true) {
            $champ->defaut === 'aujourdhui' => now()->toDateString(),
            self::estRepriseClient($champ) => $client ? data_get($client, substr($champ->defaut, 7)) : null,
            $champ->type === 'oui_non' => (bool) $champ->defaut,
            default => $champ->defaut,
        };
    }

    /** Seulement les informations reprises de la fiche du client (pour rouvrir un formulaire déjà rempli). */
    public function valeursRepriseClient(Model $client): array
    {
        return Arr::undot($this->champs
            ->filter(fn (FormulaireChamp $champ) => self::estRepriseClient($champ))
            ->mapWithKeys(fn (FormulaireChamp $champ) => [$champ->cheminEtat() => $this->valeurParDefaut($champ, $client)])
            ->all());
    }

    /** Valeur d'une question dans le formulaire ; pour une information reprise du client, celle de la fiche. */
    private function valeurDe(FormulaireChamp $champ, Get $get): mixed
    {
        return self::estRepriseClient($champ) && $this->client
            ? $this->valeurParDefaut($champ, $this->client)
            : $get($champ->cheminEtat());
    }

    private static function estRepriseClient(FormulaireChamp $champ): bool
    {
        return str_starts_with((string) $champ->defaut, 'client.');
    }

    /**
     * Liste filtrée par une autre question : s'il ne reste qu'un seul choix possible, il est coché d'office.
     *
     * @param  array<string, mixed>  $valeurs  [chemin => valeur]
     */
    public function completerChoixUniques(array $valeurs): array
    {
        foreach ($this->champs as $champ) {
            $parent = $champ->liste_parent ? $this->champs->get($champ->liste_parent) : null;

            if (! $parent || ! $champ->estUneListe() || filled($valeurs[$champ->cheminEtat()] ?? null)) {
                continue;
            }

            $options = $this->optionsPour($champ, $valeurs[$parent->cheminEtat()] ?? null);
            if (count($options) === 1) {
                $valeurs[$champ->cheminEtat()] = array_key_first($options);
            }
        }

        return $valeurs;
    }

    private function composant(FormulaireChamp $champ): Field
    {
        $composant = match ($champ->type) {
            'zone_texte' => Textarea::make($champ->cheminEtat())->rows(2),
            'nombre' => TextInput::make($champ->cheminEtat())->numeric(),
            'telephone' => $this->telephone($champ),
            'email' => TextInput::make($champ->cheminEtat())->email()->maxLength(255),
            'date' => DatePicker::make($champ->cheminEtat())->native(false)->displayFormat('d/m/Y'),
            // Liste indépendante : choix envoyés avec la page (ouverture immédiate, sans « En chargement… »).
            // Liste en cascade : choix rechargés selon la réponse dont elle dépend.
            'liste' => Select::make($champ->cheminEtat())->searchable()->options($this->options($champ))
                ->dynamicOptions(filled($champ->liste_parent)),
            'boutons' => ToggleButtons::make($champ->cheminEtat())->inline()->options($this->options($champ)),
            'oui_non' => Toggle::make($champ->cheminEtat())->inline(false),
            'photo' => $this->photo($champ),
            'gps_latitude' => $this->latitude($champ),
            'gps_longitude' => TextInput::make($champ->cheminEtat())->numeric()->minValue(-180)->maxValue(180),
            // Calcul : affiché, recalculé dès qu'une question de la formule change, non modifiable à la main
            'calcul' => TextInput::make($champ->cheminEtat())
                ->readOnly()
                ->prefixIcon(Heroicon::OutlinedCalculator)
                ->extraInputAttributes(['class' => 'gr-champ-calcule'])
                ->placeholder('Calculé automatiquement'),
            default => TextInput::make($champ->cheminEtat())->maxLength(255),
        };

        // Date de naissance du KYC : le client doit avoir de 18 à 76 ans
        if ($this->formulaire === 'kyc' && $champ->cle === 'd_nais' && $composant instanceof DatePicker) {
            $composant
                ->minDate(fn () => \App\Models\Kyc::naissanceAuPlusTot())
                ->maxDate(fn () => \App\Models\Kyc::naissanceAuPlusTard())
                ->validationMessages([
                    'after_or_equal' => 'Le client doit avoir '.\App\Models\Kyc::AGE_MAX.' ans au plus.',
                    'before_or_equal' => 'Le client doit avoir au moins '.\App\Models\Kyc::AGE_MIN.' ans.',
                ]);
            $champ->aide ??= 'Le client doit avoir entre '.\App\Models\Kyc::AGE_MIN.' et '.\App\Models\Kyc::AGE_MAX.' ans.';
        }

        $composant
            ->label($champ->libelle)
            ->helperText($champ->aide)
            ->required($champ->obligatoire)
            ->columnSpan($champ->pleine_largeur ? 'full' : 1);

        if (self::estRepriseClient($champ)) {
            // Information reprise de la fiche du client : affichée, non modifiable ici
            $composant->disabled()->dehydrated($champ->stockage !== 'aucun');
        } elseif (filled($champ->defaut)) {
            $composant->default($this->valeurParDefaut($champ));
        }

        if ($champ->stockage === 'aucun') {
            $composant->dehydrated(false);
        }

        if ($conditions = $champ->conditions) {
            $composant->visible(fn (Get $get) => $this->conditionsRemplies($conditions, $get));
        }

        // Une question dont dépendent d'autres (conditions, listes ou calculs) doit rafraîchir le formulaire
        $enfants = $this->listesDependantesDe($champ->cle);
        $calculs = $this->calculsDependantDe($champ->cle);

        if ($enfants->isNotEmpty() || $this->estReferenceeDansUneCondition($champ->cle)) {
            $composant->live();
        } elseif ($calculs->isNotEmpty()) {
            $composant->live(onBlur: true); // un nombre saisi : recalcul quand on quitte le champ
        }

        if ($enfants->isNotEmpty() || $calculs->isNotEmpty()) {
            $composant->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($enfants, $calculs, $champ) {
                $state = self::estRepriseClient($champ) && $this->client ? $this->valeurDe($champ, $get) : $state;

                // Listes qui en dépendent : vidées, ou cochées s'il ne reste qu'un choix
                foreach ($enfants as $enfant) {
                    $options = $this->optionsPour($enfant, $state);
                    $set($enfant->cheminEtat(), count($options) === 1 ? array_key_first($options) : null);
                }

                // Calculs qui l'utilisent : recalculés
                foreach ($calculs as $calcul) {
                    $set($calcul->cheminEtat(), $this->calculer($calcul, fn (string $cle) => ($source = $this->champs->get($cle)) ? $this->valeurDe($source, $get) : null));
                }
            });
        }

        return $composant;
    }

    /** Questions « Calcul » dont la formule utilise la question $cle. */
    private function calculsDependantDe(string $cle): Collection
    {
        return $this->champs->filter(fn (FormulaireChamp $champ) => $champ->type === 'calcul' && in_array($cle, Formule::variables($champ->formule), true));
    }

    private function calculer(FormulaireChamp $calcul, callable $valeur): ?string
    {
        try {
            $resultat = Formule::evaluer((string) $calcul->formule, $valeur);
        } catch (\InvalidArgumentException) {
            return null; // formule invalide : signalée dans Paramètres
        }

        return $resultat === null ? null : rtrim(rtrim(number_format($resultat, 2, '.', ''), '0'), '.');
    }

    /**
     * Recalcule toutes les questions « Calcul » à partir des données envoyées (au moment d'enregistrer) :
     * la valeur calculée fait foi, même si l'écran n'a pas été rafraîchi.
     */
    public function appliquerCalculs(array $donnees): array
    {
        foreach ($this->champs->filter(fn (FormulaireChamp $champ) => $champ->type === 'calcul') as $calcul) {
            data_set($donnees, $calcul->cheminEtat(), $this->calculer($calcul, fn (string $cle) => ($source = $this->champs->get($cle))
                ? data_get($donnees, $source->cheminEtat())
                : null));
        }

        return $donnees;
    }

    /** Photo : appareil photo sur téléphone/tablette, ou choix d'un fichier sur ordinateur. */
    private function photo(FormulaireChamp $champ): FileUpload
    {
        return FileUpload::make($champ->cheminEtat())
            ->image()
            ->disk(self::DISQUE_PHOTOS)
            ->directory("{$this->formulaire}/".now()->format('Y/m'))
            ->maxSize(8192)
            ->imagePreviewHeight('160')
            ->openable()
            ->downloadable()
            ->extraInputAttributes(['capture' => 'environment'])
            ->helperText($champ->aide ?: 'Prenez la photo avec le téléphone ou choisissez un fichier (8 Mo max).')
            // Nom du fichier d'après le modèle de Paramètres > Photos (ex. kyc_photo_id_face1_2026-10-19_KONE-Adama_1000.jpg)
            ->getUploadedFileNameForStorageUsing(function (BaseFileUpload $component, TemporaryUploadedFile $file, Get $get) use ($champ) {
                $nom = ParametresPhotos::nomFichier($this->formulaire, $champ, $this->client, [
                    'nom_client' => $get('nom_client'),
                    'prenoms_client' => $get('prenoms_client'),
                ]);
                $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
                $disque = $component->getDisk();

                // Plusieurs photos du même champ le même jour : -2, -3…
                $candidat = $nom;
                for ($n = 2; $disque->exists(trim($component->getDirectory().'/'.$candidat.'.'.$extension, '/')); $n++) {
                    $candidat = $nom.'-'.$n;
                }

                return $candidat.'.'.$extension;
            })
            // Une fois la photo enregistrée dans le CRM : copie vers le dossier Google Drive du formulaire
            ->saveUploadedFileUsing(function (BaseFileUpload $component, TemporaryUploadedFile $file) {
                $chemin = $component->saveUploadedFile($file);

                if ($chemin && ParametresPhotos::pour($this->formulaire)['actif']) {
                    EnvoyerPhotoDrive::dispatchAfterResponse($this->formulaire, $component->getDiskName(), $chemin);
                }

                return $chemin;
            });
    }

    public const DISQUE_PHOTOS = 'public';

    /**
     * Latitude avec un bouton « ma position » qui remplit aussi la longitude du même formulaire,
     * grâce au GPS du téléphone (le navigateur demande l'autorisation ; https ou localhost requis).
     */
    private function latitude(FormulaireChamp $champ): TextInput
    {
        $longitude = $this->champs->first(fn (FormulaireChamp $autre) => $autre->type === 'gps_longitude');

        return TextInput::make($champ->cheminEtat())
            ->numeric()
            ->minValue(-90)
            ->maxValue(90)
            ->suffixAction(
                Action::make('maPosition_'.$champ->cle)
                    ->icon(Heroicon::OutlinedMapPin)
                    ->tooltip('Utiliser ma position actuelle')
                    ->alpineClickHandler(function (TextInput $component) use ($champ, $longitude) {
                        $cheminLatitude = $component->getStatePath();
                        $cheminLongitude = $longitude
                            ? substr($cheminLatitude, 0, -strlen($champ->cheminEtat())).$longitude->cheminEtat()
                            : null;

                        // Guillemets simples uniquement : le code est placé dans un attribut HTML entre guillemets doubles
                        $js = fn (string $texte) => "'".addslashes($texte)."'";

                        return 'if (! navigator.geolocation) { alert('.$js('Ce navigateur ne donne pas accès au GPS.').'); return; } '
                            .'navigator.geolocation.getCurrentPosition('
                            .'(p) => { $wire.set('.$js($cheminLatitude).', p.coords.latitude.toFixed(7)); '
                            .($cheminLongitude ? '$wire.set('.$js($cheminLongitude).', p.coords.longitude.toFixed(7)); ' : '')
                            .'}, (e) => alert('.$js('Position indisponible : ').' + e.message), { enableHighAccuracy: true, timeout: 20000 })';
                    }),
            );
    }

    private function telephone(FormulaireChamp $champ): TextInput
    {
        return TextInput::make($champ->cheminEtat())
            ->tel()
            ->prefix('+225')
            ->formatStateUsing(fn (?string $state) => $state && str_starts_with($state, '225') && strlen($state) === 13 ? substr($state, 3) : $state)
            ->dehydrateStateUsing(function (?string $state) {
                $chiffres = preg_replace('/\D/', '', (string) $state);

                return $chiffres === '' ? null : (strlen($chiffres) === 10 ? '225'.$chiffres : $chiffres);
            })
            ->regex('/^\s*(\+?225)?[\s.]*\d([\s.]*\d){9}\s*$/')
            ->validationMessages(['regex' => 'Le numéro doit comporter 10 chiffres.']);
    }

    /** Options de la liste, restreintes selon la réponse à la question « parent » si elle est renseignée. */
    private function options(FormulaireChamp $champ): \Closure
    {
        $parent = $champ->liste_parent ? $this->champs->get($champ->liste_parent) : null;

        return fn (Get $get) => $this->optionsPour(
            $champ,
            $parent ? $this->valeurDe($parent, $get) : null,
            // Valeur déjà saisie : reste proposée même si le choix a été désactivé ou n'existe pas dans la liste
            $get($champ->cheminEtat()),
        );
    }

    /** Choix d'une liste, compte tenu de la réponse à la question dont elle dépend. */
    public function optionsPour(FormulaireChamp $champ, mixed $valeurParent, mixed $valeurActuelle = null): array
    {
        $parent = $champ->liste_parent ? $this->champs->get($champ->liste_parent) : null;

        if ($champ->source_liste === ListesReference::PERSONNALISEE) {
            $options = collect($champ->options ?? [])
                ->filter(fn (array $option) => ! $parent || blank($valeurParent) || blank($option['parent'] ?? null)
                    || (string) $option['parent'] === (string) $valeurParent)
                ->mapWithKeys(fn (array $option) => [$option['valeur'] => $option['libelle'] ?: $option['valeur']])
                ->all();

            if (filled($valeurActuelle) && ! is_array($valeurActuelle) && ! array_key_exists((string) $valeurActuelle, $options)) {
                $options[(string) $valeurActuelle] = (string) $valeurActuelle;
            }

            return $options;
        }

        return ListesReference::options(
            $champ->source_liste,
            $valeurActuelle,
            $parent ? $champ->liste_parent_colonne : null,
            $valeurParent,
        );
    }

    /** Toutes les conditions doivent être vraies pour afficher la question. */
    public function conditionsRemplies(array $conditions, Get $get): bool
    {
        foreach ($conditions as $condition) {
            $source = $this->champs->get($condition['champ'] ?? '');
            $valeur = $source ? $this->valeurDe($source, $get) : null;
            $valeur = is_bool($valeur) ? ($valeur ? '1' : '0') : (blank($valeur) ? null : (string) $valeur);
            $attendues = array_map('strval', $condition['valeurs'] ?? []);

            $vraie = match ($condition['operateur'] ?? 'egal') {
                'rempli' => $valeur !== null && $valeur !== '0',
                'vide' => $valeur === null || $valeur === '0',
                'different' => ! in_array($valeur, $attendues, true),
                default => in_array($valeur, $attendues, true),
            };

            if (! $vraie) {
                return false;
            }
        }

        return true;
    }

    private function listesDependantesDe(string $cle): Collection
    {
        return $this->champs->filter(fn (FormulaireChamp $champ) => $champ->liste_parent === $cle);
    }

    private function estReferenceeDansUneCondition(string $cle): bool
    {
        return $this->champs->contains(fn (FormulaireChamp $champ) => collect($champ->conditions ?? [])->contains('champ', $cle))
            || $this->configSections->contains(fn (FormulaireSection $section) => collect($section->conditions ?? [])->contains('champ', $cle));
    }
}
