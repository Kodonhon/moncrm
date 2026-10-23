@php
    /** Barre d'onglets commune de Messages : Modèles SMS / Historique / Configuration. */
    $pages = [
        'modeles' => [\App\Filament\Resources\Messages\SmsModeleResource::class, 'Modèles SMS', \Filament\Support\Icons\Heroicon::OutlinedDocumentText],
        'historique' => [\App\Filament\Resources\Messages\SmsMessageResource::class, 'Historique des envois', \Filament\Support\Icons\Heroicon::OutlinedClock],
        'configuration' => [\App\Filament\Pages\ConfigurationSms::class, 'Configuration', \Filament\Support\Icons\Heroicon::OutlinedCog6Tooth],
    ];
    $scopes = collect($scopes ?? []);
    $actif = match (true) {
        $scopes->contains(fn ($s) => str_contains($s, 'MessagesSms')) => 'historique',
        $scopes->contains(\App\Filament\Pages\ConfigurationSms::class) => 'configuration',
        default => 'modeles',
    };
    $config = \App\Models\SmsConfiguration::actuelle();
@endphp

<div class="gr-msg-entete">
    <nav class="gr-acces-onglets gr-msg-onglets" aria-label="Messages">
        @foreach ($pages as $cle => [$classe, $libelle, $icone])
            @if ($classe::canAccess())
                <a href="{{ $classe::getUrl() }}" @class(['gr-acces-onglet', 'gr-acces-onglet-actif' => $cle === $actif])
                    @if ($cle === $actif) aria-current="page" @endif>
                    <x-filament::icon :icon="$icone" class="gr-acces-onglet-icone" />
                    <span>{{ $libelle }}</span>
                </a>
            @endif
        @endforeach
    </nav>

    @if ($actif === 'modeles' && $scopes->contains(\App\Filament\Resources\Messages\Pages\ListeModelesSms::class))
        <x-crm.bandeau-alerte ton="vert" titre="SMS envoyés par les agents du CRM" :icone="\Filament\Support\Icons\Heroicon::OutlinedChatBubbleLeftRight">
            Les modèles sont rédigés par les agents puis passent par un <b>circuit d'approbation</b> : le créateur l'enregistre,
            un approbateur le valide ou le rejette. Seuls les modèles <b>approuvés et actifs</b> peuvent servir à envoyer des SMS
            (par LeTexto).
        </x-crm.bandeau-alerte>
    @endif

    @if (! $config->prete() || $config->mode_test)
        <x-crm.bandeau-alerte ton="orange" :titre="$config->mode_test ? 'Mode test : les SMS sont simulés' : 'API LeTexto pas encore configurée'">
            @if (\App\Filament\Pages\ConfigurationSms::canAccess())
                Renseignez le token et le nom d'expéditeur dans <a href="{{ \App\Filament\Pages\ConfigurationSms::getUrl() }}" class="gr-lien">Configuration</a>,
                activez l'envoi puis désactivez le mode test.
            @else
                Demandez à un administrateur de terminer la configuration de LeTexto.
            @endif
        </x-crm.bandeau-alerte>
    @endif
</div>
