@php
    $nombre = fn ($n) => number_format((float) $n, 0, ',', ' ');
@endphp

<x-filament-widgets::widget>
    <div class="gr-kpi-grille">
        <x-crm.carte-indicateur :valeur="$nombre($contrats)" libelle="Contrats avec deposit"
            :icone="\Filament\Support\Icons\Heroicon::OutlinedDocumentText" ton="violet" />
        <x-crm.carte-indicateur :valeur="$nombre($total_paye).' F'" libelle="Total payé"
            :icone="\Filament\Support\Icons\Heroicon::OutlinedBanknotes" ton="vert" />
        <x-crm.carte-indicateur :valeur="$nombre($depots_mois).' F'" :libelle="'Dépôts du mois · '.$nombre($contrats_mois).' contrats'"
            :icone="\Filament\Support\Icons\Heroicon::OutlinedCalendarDays" ton="orange" />
        <x-crm.carte-indicateur :valeur="$nombre($partiels)" libelle="Dépôts partiels"
            :icone="\Filament\Support\Icons\Heroicon::OutlinedClock" ton="rouge" />
    </div>
</x-filament-widgets::widget>
