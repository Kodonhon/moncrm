@php
    $groupes = $this->getGroupes();
    $nombre = $this->getNombreCartes();
@endphp

<x-filament-panels::page>
    <div class="gr-admin" x-data="{
        q: '',
        n(s) { return s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim() },
        ok(el) { return ! this.q || el.dataset.texte.includes(this.n(this.q)) },
    }">
        <x-crm.titre-page titre="Administration" :sous-titre="$nombre.' paramètres disponibles'"
            :icone="\Filament\Support\Icons\Heroicon::ShieldCheck" ton="violet" />

        <label class="gr-admin-recherche">
            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::MagnifyingGlass" class="gr-admin-recherche-icone" />
            <input type="search" x-model="q" placeholder="Rechercher un paramètre (nom, description…)" autocomplete="off" aria-label="Rechercher un paramètre">
        </label>

        @foreach ($groupes as $groupe => $cartes)
            <section class="gr-admin-groupe"
                x-show="! q || [...$el.querySelectorAll('[data-carte]')].some(c => ok(c))">
                <h2 class="gr-admin-groupe-titre"><span>{{ $groupe }}</span></h2>

                <div class="gr-admin-grille">
                    @foreach ($cartes as $carte)
                        <a href="{{ $carte['url'] }}" class="gr-admin-carte gr-admin-carte-{{ $carte['ton'] }}" data-carte
                            data-texte="{{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($carte['titre'].' '.$carte['description'].' '.$groupe)) }}"
                            x-show="ok($el)">
                            <x-crm.icone-ronde :icone="$carte['icone']" :ton="$carte['ton']" taille="lg" class="gr-admin-carte-rond" />
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::ArrowUpRight" class="gr-admin-carte-fleche" />
                            <span class="gr-admin-carte-titre">{{ $carte['titre'] }}</span>
                            <span class="gr-admin-carte-texte">{{ $carte['description'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        <x-crm.etat-vide x-cloak :icone="\Filament\Support\Icons\Heroicon::MagnifyingGlass" titre="Aucun paramètre trouvé"
            x-show="q && ! [...$root.querySelectorAll('[data-carte]')].some(c => ok(c))">
            Essayez un autre mot (par exemple « agent », « KYC » ou « produit »).
        </x-crm.etat-vide>
    </div>
</x-filament-panels::page>
