<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Une question d'un formulaire configurable (Paramètres > Formulaire client).
 */
class FormulaireChamp extends Model
{
    public const TYPES = [
        'texte' => 'Texte court',
        'zone_texte' => 'Texte long',
        'nombre' => 'Nombre',
        'telephone' => 'Téléphone',
        'email' => 'E-mail',
        'date' => 'Date',
        'liste' => 'Liste déroulante',
        'boutons' => 'Boutons (choix unique)',
        'oui_non' => 'Oui / Non',
        'photo' => 'Photo',
        'gps_latitude' => 'GPS : latitude (avec bouton « ma position »)',
        'gps_longitude' => 'GPS : longitude',
        'calcul' => 'Calcul (formule à partir d\'autres questions)',
    ];

    /** Types compatibles avec une colonne texte (changement possible si la question est « type modifiable »). */
    public const TYPES_TEXTE = ['texte', 'zone_texte', 'nombre', 'telephone', 'email', 'liste', 'boutons', 'calcul'];

    public const TYPES_LISTE = ['liste', 'boutons'];

    public const OPERATEURS = [
        'egal' => 'est',
        'different' => 'n\'est pas',
        'rempli' => 'est renseigné',
        'vide' => 'est vide',
    ];

    protected $table = 'formulaire_champ';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'standard' => 'boolean',
            'visible' => 'boolean',
            'obligatoire' => 'boolean',
            'verrouille' => 'boolean',
            'pleine_largeur' => 'boolean',
            'type_modifiable' => 'boolean',
            'options' => 'array',
            'conditions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        $oublier = fn (FormulaireChamp $champ) => Cache::forget(static::cleCache($champ->formulaire));

        static::saved($oublier);
        static::deleted($oublier);

        // Une question placée dans une nouvelle section crée cette section (paramétrable ensuite)
        static::saved(function (FormulaireChamp $champ) {
            FormulaireSection::firstOrCreate(
                ['formulaire' => $champ->formulaire, 'nom' => $champ->section],
                ['ordre' => $champ->ordre, 'visible' => true],
            );
        });
    }

    public static function cleCache(string $formulaire): string
    {
        return "formulaire:{$formulaire}";
    }

    /** Questions d'un formulaire, dans l'ordre (mises en cache jusqu'à la prochaine modification). */
    public static function pour(string $formulaire): \Illuminate\Support\Collection
    {
        return collect(Cache::rememberForever(
            static::cleCache($formulaire),
            fn () => static::query()->where('formulaire', $formulaire)->orderBy('ordre')->orderBy('id')->get()->map->getAttributes()->all(),
        ))->map(fn (array $attributs) => (new static)->newFromBuilder($attributs));
    }

    public function estUneListe(): bool
    {
        return in_array($this->type, self::TYPES_LISTE, true);
    }

    /** Chemin de la valeur dans les données du formulaire. */
    public function cheminEtat(): string
    {
        return $this->stockage === 'perso' ? "donnees_formulaire.{$this->cle}" : $this->cle;
    }
}
