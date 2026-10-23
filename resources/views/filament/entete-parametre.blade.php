@php
    $carte = collect($scopes ?? [])
        ->map(fn ($scope) => \App\Filament\Clusters\Parametres\Pages\Administration::carte($scope))
        ->filter()
        ->first();
@endphp

@if ($carte && $carte['groupe'] !== 'Sécurité et accès')
    <div class="gr-param-entete">
        <a href="{{ \App\Filament\Clusters\Parametres\Pages\Administration::getUrl() }}" class="gr-param-retour">
            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::ArrowLeft" class="gr-param-retour-icone" />
            Administration
            <span class="gr-param-retour-groupe">/ {{ $carte['groupe'] }}</span>
        </a>

        <x-crm.titre-page :titre="$carte['titre']" :sous-titre="$carte['description']" :icone="$carte['icone']" :ton="$carte['ton']" />
    </div>
@endif
