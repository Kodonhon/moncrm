@php
    /** @var \App\Models\Installation $installation */
    $origine = collect($installation->donnees_origine ?? []);
    $estUrl = fn ($v) => is_string($v) && str_starts_with($v, 'http');

    // Photos et signatures (images AppSheet, ou photos prises dans le CRM), documents (contrat PDF…), contrôles OUI/NON, autres informations
    $estImage = fn ($v) => str_contains($v, 'getimageurl') || preg_match('/\/storage\/.+\.(jpe?g|png|webp|gif|heic)$/i', $v);
    $photos = $origine->filter(fn ($v) => $estUrl($v) && $estImage($v));
    $documents = $origine->filter(fn ($v) => $estUrl($v) && ! $estImage($v));
    $controles = $origine->filter(fn ($v) => in_array(mb_strtoupper(trim((string) $v)), ['OUI', 'NON'], true));
    $dejaAffiches = ['ID installation', 'Nom et prenoms du client', 'Numéro de CNI du client', 'Numéro du contrat', 'num_contrat_upya',
        'Type de produit installé', 'Installation assignée à', 'Installation prise en charge par', 'Statut du technicien', 'Date d\'installation',
        'Code bare du CSB/CSD', 'Numero de serie de la pompe', 'Numero de serie des panneaux 1', 'Numero de serie des panneaux 2',
        'Type de paiement', 'Ville du client', 'Localité du client', 'contact_client', 'Géolocalisation', 'last_modified_by', 'Type d\'installation'];
    $autres = $origine->reject(fn ($v, $k) => $estUrl($v) || $controles->has($k) || in_array($k, $dejaAffiches, true));

    $installe = $installation->systeme_installe === 1;
    $info = fn ($libelle, $valeur) => filled($valeur) ? ['libelle' => $libelle, 'valeur' => $valeur] : null;
    $equipement = array_filter([
        $info('Produit installé', $installation->type_produit),
        $info('N° de série de la pompe', $installation->num_serie_pompe),
        $info('Contrôleur (CSB / CSD)', $installation->code_controleur),
        $info('Panneau 1', $installation->num_serie_panneau_1),
        $info('Panneau 2', $installation->num_serie_panneau_2),
        $info('Paiement', $installation->type_paiement),
    ]);
    $intervention = array_filter([
        $info('Date d\'installation', $installation->date_installation?->format('d/m/Y')),
        $info('Technicien', $installation->assignee_a),
        $info('Statut du technicien', $installation->statut_technicien),
        $info('Prise en charge par', $installation->prise_en_charge_par),
        $info('Localité', collect([$installation->localite, $installation->ville])->filter()->implode(' · ')),
        $info('Pièce d\'identité', $installation->num_cni),
        $info('N° de contrat', collect([$installation->num_contrat, $installation->num_contrat_upya ? 'UPYA '.$installation->num_contrat_upya : null])->filter()->implode(' · ')),
    ]);
@endphp

<div class="gr-rapport">
    <div @class(['gr-rapport-statut', 'gr-rapport-statut-ok' => $installe, 'gr-rapport-statut-ko' => ! $installe])>
        <x-crm.icone-ronde :icone="$installe ? \Filament\Support\Icons\Heroicon::OutlinedCheckBadge : \Filament\Support\Icons\Heroicon::OutlinedXCircle"
            :ton="$installe ? 'vert' : 'rouge'" />
        <div>
            <strong>{{ $installe ? 'Système installé' : 'Système non installé' }}</strong>
            <span>
                @if ($installe)
                    Kit {{ $installation->kit_recu === 1 ? 'reçu par le client' : 'non confirmé comme reçu' }}.
                @else
                    {{ $installation->cause_non_installation ?: $installation->raison_non_installation ?: 'Raison non précisée.' }}
                @endif
            </span>
        </div>
        @if ($lienFiche)
            <a href="{{ $lienFiche }}" class="gr-rapport-lien">Fiche du client →</a>
        @endif
    </div>

    <div class="gr-rapport-grille">
        <section class="gr-rapport-bloc">
            <h3>Intervention</h3>
            <dl>
                @foreach ($intervention as $l)
                    <div><dt>{{ $l['libelle'] }}</dt><dd>{{ $l['valeur'] }}</dd></div>
                @endforeach
            </dl>
        </section>
        <section class="gr-rapport-bloc">
            <h3>Équipement</h3>
            <dl>
                @forelse ($equipement as $l)
                    <div><dt>{{ $l['libelle'] }}</dt><dd class="gr-mono">{{ $l['valeur'] }}</dd></div>
                @empty
                    <p class="gr-vide-valeur">Aucun numéro de série renseigné.</p>
                @endforelse
            </dl>
        </section>
    </div>

    @if ($controles->isNotEmpty())
        <section class="gr-rapport-bloc">
            <h3>Contrôles</h3>
            <ul class="gr-rapport-controles">
                @foreach ($controles as $question => $reponse)
                    @php $oui = mb_strtoupper(trim($reponse)) === 'OUI'; @endphp
                    <li @class(['gr-ok' => $oui, 'gr-ko' => ! $oui])>
                        <span aria-hidden="true">{{ $oui ? '✓' : '✗' }}</span>{{ $question }}
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($autres->isNotEmpty())
        <section class="gr-rapport-bloc">
            <h3>Mesures et informations</h3>
            <dl class="gr-rapport-dl-large">
                @foreach ($autres as $question => $reponse)
                    <div><dt>{{ $question }}</dt><dd>{{ $reponse }}</dd></div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($photos->isNotEmpty())
        <section class="gr-rapport-bloc">
            <h3>Photos et signatures <small>({{ $photos->count() }})</small></h3>
            <div class="gr-rapport-photos">
                @foreach ($photos as $libelle => $url)
                    <a href="{{ $url }}" target="_blank" rel="noopener" title="{{ $libelle }}">
                        <img src="{{ $url }}" alt="{{ $libelle }}" loading="lazy">
                        <span>{{ \Illuminate\Support\Str::limit($libelle, 40) }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($documents->isNotEmpty())
        <section class="gr-rapport-bloc">
            <h3>Documents</h3>
            <ul class="gr-rapport-documents">
                @foreach ($documents as $libelle => $url)
                    <li><a href="{{ $url }}" target="_blank" rel="noopener">{{ $libelle === 'lien_contrat' ? 'Contrat signé (PDF)' : $libelle }} ↗</a></li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
