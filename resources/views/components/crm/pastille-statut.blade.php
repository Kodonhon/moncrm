@props(['statut', 'libelle' => null])
{{-- Pastille de statut : fond teinté + icône + libellé (table unique App\Support\Statuts) --}}
@php
    $info = \App\Support\Statuts::info($statut, $libelle);
@endphp
<span {{ $attributes->class(['gr-pastille-statut', "gr-ton-pale-{$info['ton']}", "gr-ton-texte-{$info['ton']}"]) }}>
    <x-filament::icon :icon="$info['icone']" class="gr-pastille-statut-icone" />
    {{ $libelle ?? $info['libelle'] }}
</span>
