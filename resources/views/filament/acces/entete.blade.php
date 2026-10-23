{{-- En-tête commun des écrans « Rôles et accès » : retour et titre.
     Plus de barre d'onglets : Utilisateurs, Rôles et Permissions des rôles sont dans le menu « Administration ». --}}
<div class="gr-param-entete gr-acces-entete">
    <a href="{{ \App\Filament\Clusters\Parametres\Pages\Administration::getUrl() }}" class="gr-param-retour">
        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::ArrowLeft" class="gr-param-retour-icone" />
        Administration
        <span class="gr-param-retour-groupe">/ Sécurité et accès</span>
    </a>

    <x-crm.titre-page titre="Rôles et accès"
        sous-titre="Qui se connecte, ce que chaque rôle peut faire et quelles données il voit."
        :icone="\Filament\Support\Icons\Heroicon::OutlinedShieldCheck" ton="rouge" />
</div>
