@php
    use Filament\Support\Icons\Heroicon;

    $nombre = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $maxMois = max(1, collect($d['mois_series'])->max('valeur'));
    $maxAns = max(1, collect($d['annees_series'])->max('valeur'));
    $totalSources = max(1, collect($d['sources'])->sum('valeur'));
    $couleursSources = ['#5AAA3C', '#3E3765', '#E8752B', '#94A3B8', '#2B2747', '#B4530F'];

    // Anneau des sources : une part de cercle par source
    $angle = 0;
    $parts = [];
    foreach ($d['sources'] as $i => $s) {
        $fin = $angle + $s['valeur'] / $totalSources * 360;
        $parts[] = $couleursSources[$i % count($couleursSources)].' '.round($angle, 2).'deg '.round($fin, 2).'deg';
        $angle = $fin;
    }
    $anneau = 'conic-gradient('.implode(', ', $parts ?: ['#e5e7eb 0deg 360deg']).')';

    $debutPeriode = now()->startOfYear()->translatedFormat('d M');
    $finPeriode = now()->translatedFormat('d M Y');
    $lien = fn ($id) => \App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $id]);
@endphp

<div class="tb">
    {{-- En-tête --}}
    <div class="tb-entete">
        <h1 class="tb-salut">Bonjour, <span>{{ $prenom }}</span></h1>
        <div class="tb-entete-actions">
            <span class="tb-periode"><x-filament::icon :icon="Heroicon::OutlinedCalendarDays" class="tb-i" /> {{ $debutPeriode }} – {{ $finPeriode }}</span>
            @if ($lienNouveauClient)
                <a href="{{ $lienNouveauClient }}" class="tb-bouton tb-bouton-plein"><x-filament::icon :icon="Heroicon::OutlinedPlus" class="tb-i" /> Nouveau client</a>
            @endif
        </div>
    </div>

    <div class="tb-grille">
        {{-- Colonne gauche --}}
        <div class="tb-col">
            <section class="tb-carte">
                <header class="tb-carte-entete">
                    <div><h2>Portefeuille</h2><p>Dossiers en cours (KYC fait, pas encore installés)</p></div>
                    <a href="{{ $lienClients }}" class="tb-fleche" aria-label="Voir les clients">↗</a>
                </header>
                <div class="tb-carte-banque">
                    <div class="tb-banque-haut"><strong>GREENO</strong><span aria-hidden="true">)))</span></div>
                    <span class="tb-banque-libelle">Dossiers en cours</span>
                    <span class="tb-banque-valeur">{{ $nombre($d['en_cours']) }}</span>
                    <div class="tb-banque-bas"><span>•••• {{ $nombre($d['total']) }} CLIENTS</span><span>LIVE</span></div>
                </div>
            </section>

            <section class="tb-carte tb-carte-compacte">
                <p class="tb-libelle">Nouveaux clients cette semaine</p>
                <div class="tb-ligne">
                    <span class="tb-gros">{{ $nombre($d['semaine']) }}</span>
                    @if ($d['evolution_semaine'] !== null)
                        <span @class(['tb-evolution', 'tb-baisse' => $d['evolution_semaine'] < 0])>{{ $d['evolution_semaine'] >= 0 ? '↗' : '↘' }} {{ str_replace('.', ',', (string) abs($d['evolution_semaine'])) }} %</span>
                    @endif
                </div>
            </section>

            <section class="tb-carte tb-carte-compacte">
                <div class="tb-titre-icone">
                    <span class="tb-pastille-icone"><x-filament::icon :icon="Heroicon::OutlinedCheckBadge" class="tb-i" /></span>
                    <div><h2>Conversion</h2><p>Clients avec KYC installés</p></div>
                </div>
                <div class="tb-ligne">
                    <span class="tb-gros">{{ str_replace('.', ',', (string) $d['taux_installation']) }}<small>%</small></span>
                </div>
                <p class="tb-note">{{ $nombre($d['installes']) }} installations · {{ $nombre($d['echecs']) }} non abouties</p>
            </section>

            <section class="tb-carte tb-carte-compacte">
                <header class="tb-carte-entete">
                    <div class="tb-titre-icone">
                        <span class="tb-pastille-icone"><x-filament::icon :icon="Heroicon::OutlinedPhoneArrowUpRight" class="tb-i" /></span>
                        <div><h2>Relances CX</h2><p>À rappeler en priorité</p></div>
                    </div>
                </header>
                @forelse ($d['relances'] as $r)
                    <a href="{{ $lien($r['id']) }}" class="tb-relance"><strong>{{ $r['nom'] }}</strong><small>{{ $r['detail'] }}</small></a>
                @empty
                    <p class="tb-vide">Aucune relance en attente 🎉</p>
                @endforelse
            </section>
        </div>

        {{-- Colonne centrale --}}
        <div class="tb-col">
            <section class="tb-carte" x-data="{ vue: 'mois' }">
                <header class="tb-carte-entete">
                    <div class="tb-titre-icone">
                        <span class="tb-pastille-icone"><x-filament::icon :icon="Heroicon::OutlinedChartBar" class="tb-i" /></span>
                        <div><h2>Nouveaux clients</h2><p>Enregistrements par période</p></div>
                    </div>
                    <div class="tb-bascule" role="tablist">
                        <button type="button" :class="vue === 'mois' && 'tb-actif'" @click="vue = 'mois'">Mensuel</button>
                        <button type="button" :class="vue === 'ans' && 'tb-actif'" @click="vue = 'ans'">Annuel</button>
                    </div>
                </header>
                @foreach (['mois' => [$d['mois_series'], $maxMois], 'ans' => [$d['annees_series'], $maxAns]] as $vue => [$serie, $max])
                    <div class="tb-barres" x-show="vue === '{{ $vue }}'" @if ($vue === 'ans') x-cloak @endif>
                        @foreach ($serie as $i => $p)
                            <div @class(['tb-barre', 'tb-barre-derniere' => $loop->last]) title="{{ $p['detail'] }} : {{ $nombre($p['valeur']) }} clients">
                                <span class="tb-barre-valeur">{{ $nombre($p['valeur']) }}</span>
                                <span class="tb-barre-remplie" style="height: {{ max(2, round($p['valeur'] / $max * 100)) }}%"></span>
                                <span class="tb-barre-libelle">{{ $p['libelle'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </section>

            <section class="tb-carte">
                <header class="tb-carte-entete">
                    <div><h2>Activité des clients</h2><p>Derniers clients enregistrés</p></div>
                    <a href="{{ $lienClients }}" class="tb-fleche" aria-label="Voir tous les clients">↗</a>
                </header>
                <table class="tb-tableau">
                    <thead><tr><th>Client</th><th>Date</th><th>Lieu</th><th>Étape</th></tr></thead>
                    <tbody>
                        @foreach ($d['recents'] as $r)
                            <tr onclick="window.location='{{ $lien($r['id']) }}'">
                                <td><span class="gr-liste-client">{!! $r['avatar'] !!}<span class="gr-liste-nom">{{ $r['nom'] }}</span></span></td>
                                <td>{{ $r['date'] }}</td>
                                <td class="tb-doux">{{ $r['lieu'] ?? '—' }}</td>
                                <td><span class="tb-statut tb-statut-{{ $r['etape'][1] }}">{{ $r['etape'][0] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="tb-carte">
                <header class="tb-carte-entete">
                    <div class="tb-titre-icone">
                        <span class="tb-pastille-icone"><x-filament::icon :icon="Heroicon::OutlinedSquare3Stack3d" class="tb-i" /></span>
                        <div><h2>Parcours par étape</h2><p>Du prospect à l'installation</p></div>
                    </div>
                </header>
                @foreach ($d['etapes'] as [$libelle, $valeur, $ton])
                    @php $part = $d['total'] ? round($valeur / $d['total'] * 100) : 0; @endphp
                    <div class="tb-etape">
                        <div class="tb-etape-haut">
                            <span><i class="tb-point tb-point-{{ $ton }}"></i>{{ $libelle }} · {{ $nombre($valeur) }}</span>
                            <span class="tb-doux">{{ $part }} %</span>
                        </div>
                        <div class="tb-jauge"><span class="tb-jauge-{{ $ton }}" style="width: {{ max(1, $part) }}%"></span></div>
                    </div>
                @endforeach
            </section>
        </div>

        {{-- Colonne droite --}}
        <div class="tb-col">
            <section class="tb-carte">
                <header class="tb-carte-entete">
                    <div><h2>Installations</h2><p>Systèmes installés</p></div>
                    <a href="{{ \App\Filament\Resources\Installations\InstallationResource::getUrl() }}" class="tb-fleche" aria-label="Voir les installations">↗</a>
                </header>
                <div class="tb-centre">
                    <span class="tb-libelle">Total installé</span>
                    <span class="tb-tres-gros">{{ $nombre($d['installes']) }}</span>
                    <span class="tb-evolution">+{{ $nombre($d['installes_mois']) }} ce mois-ci</span>
                </div>
                <div class="tb-boutons">
                    @if ($lienNouveauClient)
                        <a href="{{ $lienNouveauClient }}" class="tb-bouton tb-bouton-plein"><x-filament::icon :icon="Heroicon::OutlinedPlus" class="tb-i" /> Client</a>
                    @endif
                    <a href="{{ \App\Filament\Pages\AssignationKits::getUrl() }}" class="tb-bouton"><x-filament::icon :icon="Heroicon::OutlinedCube" class="tb-i" /> Kits</a>
                </div>
            </section>

            <section class="tb-carte tb-carte-info">
                <div class="tb-titre-icone">
                    <span class="tb-pastille-icone"><x-filament::icon :icon="Heroicon::OutlinedSparkles" class="tb-i" /></span>
                    <div><h2>À faire aujourd'hui</h2><p>Les files d'attente de l'équipe</p></div>
                </div>
                <ul class="tb-files">
                    <li><a href="{{ \App\Filament\Pages\ValidationCredit::getUrl() }}">Validation crédit <b>{{ \App\Filament\Pages\ValidationCredit::getNavigationBadge() ?? 0 }}</b></a></li>
                    <li><a href="{{ \App\Filament\Resources\PreparationInstallations\PreparationInstallationResource::getUrl() }}">Appels CX <b>{{ \App\Filament\Resources\PreparationInstallations\PreparationInstallationResource::getNavigationBadge() ?? 0 }}</b></a></li>
                    <li><a href="{{ \App\Filament\Pages\AssignationKits::getUrl() }}">Kits à assigner <b>{{ \App\Filament\Pages\AssignationKits::getNavigationBadge() ?? 0 }}</b></a></li>
                </ul>
            </section>

            <section class="tb-carte">
                <div class="tb-titre-icone">
                    <span class="tb-pastille-icone"><x-filament::icon :icon="Heroicon::OutlinedChartPie" class="tb-i" /></span>
                    <div><h2>Clients par source</h2><p>D'où viennent les clients</p></div>
                </div>
                <div class="tb-anneau-bloc">
                    <div class="tb-anneau" style="background: {{ $anneau }}">
                        <div><strong>{{ $nombre($d['total']) }}</strong><small>clients</small></div>
                    </div>
                    <ul class="tb-legende">
                        @foreach ($d['sources'] as $i => $s)
                            <li><i style="background: {{ $couleursSources[$i % count($couleursSources)] }}"></i>{{ $s['libelle'] }}<b>{{ $nombre($s['valeur']) }}</b></li>
                        @endforeach
                    </ul>
                </div>
            </section>
        </div>
    </div>

    <p class="tb-maj">Chiffres mis à jour à {{ $d['calcule_a'] }} (actualisés toutes les 5 minutes).</p>
</div>
