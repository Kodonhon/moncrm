@php
    use Filament\Support\Icons\Heroicon;

    $nombre = fn ($n) => number_format((int) $n, 0, ',', ' ');
@endphp

<x-filament-widgets::widget>
    <div class="gr-kpi-grille gr-kpi-ttp">
        <div class="gr-kpi">
            <span class="gr-kpi-icone"><x-filament::icon :icon="Heroicon::OutlinedUsers" /></span>
            <div><span class="gr-kpi-libelle">Clients</span><span class="gr-kpi-valeur">{{ $nombre($total) }}</span></div>
            <span class="gr-kpi-note">+{{ $nombre($semaine) }} en 7 j</span>
        </div>
        <div class="gr-kpi">
            <span class="gr-kpi-icone"><x-filament::icon :icon="Heroicon::OutlinedIdentification" /></span>
            <div><span class="gr-kpi-libelle">KYC faits</span><span class="gr-kpi-valeur">{{ $nombre($kyc) }}</span></div>
            <span class="gr-kpi-note">{{ $nombre($enCours) }} en cours</span>
        </div>
        <div class="gr-kpi">
            <span class="gr-kpi-icone"><x-filament::icon :icon="Heroicon::OutlinedArrowTrendingUp" /></span>
            <div><span class="gr-kpi-libelle">Dimensionnés</span><span class="gr-kpi-valeur">{{ $nombre($dimensionnes) }}</span></div>
        </div>
        @if ($contrats !== null)
            <div class="gr-kpi">
                <span class="gr-kpi-icone gr-kpi-icone-vert"><x-filament::icon :icon="Heroicon::OutlinedDocumentCheck" /></span>
                <div><span class="gr-kpi-libelle">Contrats</span><span class="gr-kpi-valeur">{{ $nombre($contrats) }}</span></div>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
