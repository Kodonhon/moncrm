<?php

namespace App\Formulaires;

use App\Models\FormulaireChamp;
use App\Models\FormulaireSection;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Fiche de consultation construite à partir de la même configuration que le formulaire
 * (Paramètres > Formulaire …) : mêmes sections, même ordre, mêmes questions ajoutées.
 */
class ConstructeurFiche
{
    /** @var Collection<int, FormulaireChamp> */
    private Collection $champs;

    /**
     * @param  string[]  $clesExclues  questions déjà affichées ailleurs (ex. dans le résumé)
     * @param  string|null  $relation  lire les valeurs sur une relation du modèle affiché (ex. « kyc »)
     */
    /** @var Collection<string, FormulaireSection> */
    private Collection $configSections;

    public function __construct(string $formulaire, private array $clesExclues = [], private ?string $relation = null)
    {
        $this->champs = FormulaireChamp::pour($formulaire);
        $this->configSections = FormulaireSection::pour($formulaire);
    }

    /** Nombre de colonnes des informations dans chaque section. */
    private int $colonnes = 2;

    /** Masquer les informations non renseignées (fiche plus compacte). */
    private bool $masquerVides = false;

    private int $hauteurPhotos = 180;

    /** Présentation resserrée : sections compactes, plus de colonnes, champs vides masqués. */
    public function compacte(int $colonnes = 3, bool $masquerVides = true, int $hauteurPhotos = 90): static
    {
        $this->colonnes = $colonnes;
        $this->masquerVides = $masquerVides;
        $this->hauteurPhotos = $hauteurPhotos;

        return $this;
    }

    public function sections(): array
    {
        return $this->champsAffiches()
            ->groupBy('section')
            // Même ordre et mêmes sections masquées que le formulaire (Paramètres > Sections)
            ->reject(fn (Collection $champs, string $nom) => $this->configSections->has($nom) && ! $this->configSections[$nom]->visible)
            ->sortBy(fn (Collection $champs, string $nom) => $this->configSections[$nom]->ordre ?? $champs->min('ordre'))
            ->map(fn (Collection $champs, string $section) => Section::make($section)
                ->compact($this->masquerVides)
                // Fiche compacte : une section sans aucune information renseignée est masquée
                ->hidden(fn (Model $record) => $this->masquerVides
                    && $champs->every(fn (FormulaireChamp $champ) => blank($this->valeurBrute($record, $champ))))
                ->columns(['default' => 2, 'md' => $champs->every(fn ($champ) => $champ->type === 'photo') ? 3 : $this->colonnes])
                ->schema($champs->map(fn (FormulaireChamp $champ) => $this->entree($champ))->all()))
            ->values()
            ->all();
    }

    /** Entrées sans sections (pour les placer dans une section existante). */
    public function entrees(): array
    {
        return $this->champsAffiches()->map(fn (FormulaireChamp $champ) => $this->entree($champ))->values()->all();
    }

    private function champsAffiches(): Collection
    {
        return $this->champs->filter(fn (FormulaireChamp $champ) => ($champ->visible || $champ->verrouille)
            && $champ->stockage !== 'aucun'
            && ! in_array($champ->cle, $this->clesExclues, true));
    }

    private function valeurBrute(Model $record, FormulaireChamp $champ): mixed
    {
        $valeur = data_get($record, ($this->relation ? "{$this->relation}." : '').$champ->cheminEtat());

        return $valeur === 'nd' ? null : $valeur;
    }

    private function entree(FormulaireChamp $champ): TextEntry|ImageEntry
    {
        $valeur = fn (Model $record) => $this->valeurBrute($record, $champ);

        if ($champ->type === 'photo') {
            return $this->photo($champ, $valeur);
        }

        // Coordonnées GPS : la latitude ouvre la position sur Google Maps
        $longitude = $champ->type === 'gps_latitude'
            ? $this->champs->first(fn (FormulaireChamp $autre) => $autre->type === 'gps_longitude')
            : null;

        return TextEntry::make('fiche_'.($this->relation ? "{$this->relation}_" : '').$champ->cle)
            ->label($champ->libelle)
            ->state(fn (Model $record) => self::formater($champ, $valeur($record)))
            ->url(fn (Model $record) => $longitude && filled($valeur($record)) && filled($lng = $this->valeurBrute($record, $longitude))
                ? 'https://www.google.com/maps?q='.$valeur($record).','.$lng
                : null, shouldOpenInNewTab: true)
            ->placeholder('—')
            ->columnSpan($champ->pleine_largeur ? 'full' : 1)
            // Une question sans réponse n'encombre pas la fiche (toujours si elle est conditionnelle)
            ->hidden(fn (Model $record) => ($this->masquerVides || filled($champ->conditions)) && blank($valeur($record)));
    }

    /** Photo enregistrée dans le CRM : affichée. Photo importée dont le fichier n'est pas sur le serveur : on l'indique. */
    private function photo(FormulaireChamp $champ, \Closure $valeur): ImageEntry|TextEntry
    {
        $disque = ConstructeurFormulaire::DISQUE_PHOTOS;
        $existe = fn (Model $record) => filled($chemin = $valeur($record)) && $chemin !== 'nd' && Storage::disk($disque)->exists($chemin);

        return ImageEntry::make('fiche_'.($this->relation ? "{$this->relation}_" : '').$champ->cle)
            ->label($champ->libelle)
            ->state(fn (Model $record) => $existe($record) ? $valeur($record) : null)
            ->disk($disque)
            ->imageHeight($this->hauteurPhotos)
            // Vignette qui tient dans sa colonne, quel que soit le format de la photo (clic : taille réelle)
            ->extraAttributes(['class' => 'gr-photo-fiche'])
            ->extraImgAttributes(['class' => 'gr-photo-vignette', 'loading' => 'lazy'])
            ->url(fn (Model $record) => $existe($record) ? Storage::disk($disque)->url($valeur($record)) : null, shouldOpenInNewTab: true)
            ->tooltip(fn (Model $record) => filled($valeur($record)) && ! $existe($record) ? 'Fichier d\'origine : '.$valeur($record) : null)
            ->placeholder(fn (Model $record) => blank($valeur($record)) ? 'Aucune photo' : 'Photo d\'origine non disponible');
    }

    public static function formater(FormulaireChamp $champ, mixed $valeur): ?string
    {
        if (blank($valeur) && $valeur !== false && $valeur !== 0) {
            return null;
        }

        return match (true) {
            $champ->type === 'oui_non' => $valeur ? 'Oui' : 'Non',
            $champ->type === 'telephone' => preg_match('/^225(\d{10})$/', (string) $valeur, $m) ? '+225 '.trim(chunk_split($m[1], 2, ' ')) : (string) $valeur,
            $champ->type === 'date' => $valeur instanceof \DateTimeInterface ? $valeur->format('d/m/Y') : \Illuminate\Support\Carbon::parse($valeur)->format('d/m/Y'),
            $champ->estUneListe() && $champ->source_liste === ListesReference::PERSONNALISEE => collect($champ->options ?? [])
                ->firstWhere('valeur', (string) $valeur)['libelle'] ?? (string) $valeur,
            $champ->estUneListe() => ListesReference::libelle($champ->source_liste, $valeur),
            default => (string) $valeur,
        };
    }
}
