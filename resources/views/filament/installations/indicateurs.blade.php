@php
    $nombre = fn ($n) => number_format($n, 0, ',', ' ');
@endphp

<x-filament-widgets::widget>
    <div class="gr-kpi-grille">
        <x-crm.carte-indicateur :valeur="$nombre($total)" libelle="Rapports d'installation"
            :icone="\Filament\Support\Icons\Heroicon::OutlinedClipboardDocumentList" ton="violet" />
        <x-crm.carte-indicateur :valeur="$nombre($installes)" libelle="Systèmes installés"
            :icone="\Filament\Support\Icons\Heroicon::OutlinedCheckBadge" ton="vert" />
        <x-crm.carte-indicateur :valeur="$nombre($nonInstalles)" libelle="Non installés"
            :icone="\Filament\Support\Icons\Heroicon::OutlinedXCircle" ton="rouge" />
        <x-crm.carte-indicateur :valeur="str_replace('.', ',', (string) $taux).' %'" :libelle="'Taux de réussite · '.$nombre($clientsInstalles).' clients'"
            :icone="\Filament\Support\Icons\Heroicon::OutlinedChartPie" ton="orange" />
    </div>
</x-filament-widgets::widget>
