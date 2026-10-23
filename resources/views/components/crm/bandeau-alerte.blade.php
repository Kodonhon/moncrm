@props(['titre', 'ton' => 'violet', 'icone' => null])
{{-- Bandeau d'alerte : info = violet, avertissement = orange, critique = rouge, succès = vert --}}
@php
    $icone ??= match ($ton) {
        'vert' => \Filament\Support\Icons\Heroicon::OutlinedCheckCircle,
        'orange' => \Filament\Support\Icons\Heroicon::OutlinedExclamationTriangle,
        'rouge' => \Filament\Support\Icons\Heroicon::OutlinedNoSymbol,
        default => \Filament\Support\Icons\Heroicon::OutlinedInformationCircle,
    };
@endphp
<div role="{{ in_array($ton, ['rouge', 'orange']) ? 'alert' : 'status' }}" {{ $attributes->class(['gr-bandeau-alerte', "gr-ton-pale-{$ton}"]) }}>
    <x-crm.icone-ronde :icone="$icone" :ton="$ton" taille="sm" />
    <div>
        <strong class="gr-ton-texte-{{ $ton }}">{{ $titre }}</strong>
        @if ($slot->isNotEmpty())
            <div class="gr-bandeau-alerte-texte">{{ $slot }}</div>
        @endif
    </div>
</div>
