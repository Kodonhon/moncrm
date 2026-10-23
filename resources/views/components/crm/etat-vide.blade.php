@props(['icone', 'titre', 'ton' => 'violet'])
{{-- État vide : icône ronde, titre, explication, action éventuelle (slot « actions ») --}}
<div {{ $attributes->class(['gr-etat-vide-crm']) }}>
    <x-crm.icone-ronde :icone="$icone" :ton="$ton" taille="lg" />
    <h4>{{ $titre }}</h4>
    @if ($slot->isNotEmpty())
        <p>{{ $slot }}</p>
    @endif
    @isset($actions)
        <div class="gr-etat-vide-crm-actions">{{ $actions }}</div>
    @endisset
</div>
