@php
    // Mêmes entrées que le menu du haut (droits d'accès compris) ; Paramètres en bas, à part
    $elements = collect(filament()->getNavigation())
        ->flatMap(fn ($groupe) => $groupe->getItems())
        ->filter(fn ($item) => $item->isVisible())
        ->values();
    [$bas, $haut] = $elements->partition(fn ($item) => $item->getLabel() === 'Paramètres');
@endphp

<nav class="gr-rail" aria-label="Menu principal (icônes)">
  <div class="gr-rail-capsule">
    <div class="gr-rail-groupe">
        @foreach ($haut as $item)
            <a href="{{ $item->getUrl() }}" @class(['gr-rail-lien', 'gr-rail-actif' => $item->isActive()])
                title="{{ $item->getLabel() }}" aria-label="{{ $item->getLabel() }}" @if ($item->isActive()) aria-current="page" @endif>
                <x-filament::icon :icon="$item->getIcon() ?? \Filament\Support\Icons\Heroicon::OutlinedSquares2x2" class="gr-rail-icone" />
                @if (filled($badge = $item->getBadge()))
                    <span class="gr-rail-point" aria-hidden="true"></span>
                @endif
            </a>
        @endforeach
    </div>

    <div class="gr-rail-groupe">
        <span class="gr-rail-separateur" aria-hidden="true"></span>
        @foreach ($bas as $item)
            <a href="{{ $item->getUrl() }}" @class(['gr-rail-lien', 'gr-rail-actif' => $item->isActive()])
                title="{{ $item->getLabel() }}" aria-label="{{ $item->getLabel() }}">
                <x-filament::icon :icon="$item->getIcon() ?? \Filament\Support\Icons\Heroicon::OutlinedCog6Tooth" class="gr-rail-icone" />
            </a>
        @endforeach
    </div>
  </div>
</nav>
