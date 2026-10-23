@props(['icone', 'titre', 'ton' => 'vert', 'href' => null])
{{-- Bloc à fond teinté pâle : icône ronde, titre en capitales colorées, texte court --}}
<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class(['gr-carte-bloc', "gr-ton-pale-{$ton}", 'gr-carte-bloc-lien' => $href]) }}>
    <x-crm.icone-ronde :icone="$icone" :ton="$ton" />
    <div class="gr-carte-bloc-corps">
        <span class="gr-carte-bloc-titre gr-ton-texte-{{ $ton }}">{{ $titre }}</span>
        <div class="gr-carte-bloc-texte">{{ $slot }}</div>
    </div>
</{{ $href ? 'a' : 'div' }}>
