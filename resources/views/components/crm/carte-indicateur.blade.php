@props(['valeur', 'libelle', 'icone', 'ton' => 'vert', 'evolution' => null, 'href' => null])
{{-- Indicateur : chiffre, libellé, évolution (texte + flèche, pas seulement la couleur), cliquable --}}
<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class(['gr-carte-indicateur', 'gr-carte-bloc-lien' => $href]) }}>
    <x-crm.icone-ronde :icone="$icone" :ton="$ton" />
    <div>
        <span class="gr-carte-indicateur-valeur">{{ $valeur }}</span>
        <span class="gr-carte-indicateur-libelle">{{ $libelle }}</span>
        @if (filled($evolution))
            <span @class(['gr-carte-indicateur-evolution', 'gr-ton-texte-vert' => $evolution >= 0, 'gr-ton-texte-rouge' => $evolution < 0])>
                {{ $evolution >= 0 ? '▲ +' : '▼ ' }}{{ $evolution }} %
            </span>
        @endif
    </div>
</{{ $href ? 'a' : 'div' }}>
