@props(['titre', 'sousTitre' => null, 'icone' => null, 'ton' => 'violet'])
{{-- Grand titre violet, sous-titre vert, trait orange dessous --}}
<div {{ $attributes->class(['gr-titre-page']) }}>
    @if ($icone)
        <x-crm.icone-ronde :icone="$icone" :ton="$ton" taille="lg" />
    @endif
    <div>
        <h1 class="gr-titre-page-titre">{{ $titre }}</h1>
        @if ($sousTitre)
            <p class="gr-titre-page-sous-titre">{{ $sousTitre }}</p>
        @endif
        <span class="gr-trait-orange"></span>
    </div>
</div>
