@props(['icone', 'ton' => 'vert', 'taille' => 'md'])
{{-- Cercle plein de couleur + pictogramme blanc (style de l'affiche GREENO). Tons : vert, violet, orange, rouge, gris --}}
<span {{ $attributes->class(['gr-icone-ronde', "gr-ton-plein-{$ton}", "gr-icone-ronde-{$taille}"]) }} aria-hidden="true">
    <x-filament::icon :icon="$icone" class="gr-icone-ronde-svg" />
</span>
