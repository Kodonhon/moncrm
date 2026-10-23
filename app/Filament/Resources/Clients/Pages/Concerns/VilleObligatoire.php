<?php

namespace App\Filament\Resources\Clients\Pages\Concerns;

use App\Models\Localite;
use Illuminate\Support\Facades\DB;

/**
 * Formulaire client : la Ville est obligatoire, la Localité (quartier, village) facultative.
 * Sans localité précise, le client est rattaché à la localité qui porte le nom de la ville
 * (chaque ville en a une : ABIDJAN, BOUAKE…), d'où sont déduits la ville et le shop.
 */
trait VilleObligatoire
{
    /** Ville choisie (champ « ville_filtre », non enregistré en base) → localité du client si aucune n'est choisie. */
    protected function completerLocalite(array $data): array
    {
        $ville = $this->data['ville_filtre'] ?? null;

        if (blank($data['id_localite_commune'] ?? null) && filled($ville)) {
            $data['id_localite_commune'] = DB::table('localite_commune')
                ->where('id_ville', $ville)
                ->whereRaw('upper(lib_localite) = upper((select lib_ville from ville where id_ville = ?))', [$ville])
                ->value('id_localite_commune')
                // Ville sans localité homonyme : sa première localité
                ?? DB::table('localite_commune')->where('id_ville', $ville)->orderBy('id_localite_commune')->value('id_localite_commune');
        }

        return $data;
    }

    /** À l'ouverture d'un client existant : la ville est reprise de sa localité. */
    protected function remplirVille(array $data): array
    {
        if (filled($data['id_localite_commune'] ?? null)) {
            $data['ville_filtre'] = Localite::query()->whereKey($data['id_localite_commune'])->value('id_ville');
        }

        return $data;
    }
}
