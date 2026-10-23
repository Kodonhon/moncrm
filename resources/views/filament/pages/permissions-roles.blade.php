@use('App\Filament\Clusters\Parametres\Pages\PermissionsRoles')
@use('App\Support\Acces')
@php
    $roleActif = $this->getRoleActif();
    $accordees = $this->getPermissionsDuRole();
    $modules = $this->getModules();
    $courant = Acces::MODULES[$module] ?? null;
    $modifiable = $this->peutModifier();
@endphp

<x-filament-panels::page>
    <div class="gr-acces">
        {{-- En-tête et rôle modifié sur une même ligne : les modules commencent plus haut --}}
        <div class="gr-perm-haut">
            @include('filament.acces.entete', ['actif' => 'permissions'])

            <label class="gr-perm-role">
                <span>Rôle modifié</span>
                <select wire:model.live="role">
                    @foreach ($this->getOptionsRoles() as $id => $nom)
                        <option value="{{ $id }}">{{ $nom }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="gr-perm">
            {{-- Modules --}}
            <aside class="gr-perm-modules">
                <span class="gr-perm-surtitre">Modules</span>
                <label class="gr-perm-recherche">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::MagnifyingGlass" class="gr-perm-recherche-icone" />
                    <input type="search" wire:model.live.debounce.250ms="recherche" placeholder="Rechercher un module…" />
                </label>

                <nav class="gr-perm-liste">
                    @forelse ($modules as $cle => $m)
                        <button type="button" wire:click="choisirModule('{{ $cle }}')"
                            @class(['gr-perm-module', 'gr-perm-module-actif' => $cle === $module])>
                            <x-filament::icon :icon="$m['icone']" class="gr-perm-module-icone" />
                            <span class="gr-perm-module-nom">{{ $m['libelle'] }}</span>
                            <span @class(['gr-perm-compte', 'gr-perm-compte-vide' => $m['accordees'] === 0])>{{ $m['accordees'] }}/{{ count($m['actions']) }}</span>
                        </button>
                    @empty
                        <p class="gr-perm-vide">Aucun module ne correspond.</p>
                    @endforelse
                </nav>
            </aside>

            {{-- Actions du module choisi --}}
            <section class="gr-perm-detail">
                @if ($courant)
                    <header class="gr-perm-detail-tete">
                        <span class="gr-perm-detail-icone">
                            <x-filament::icon :icon="$modules[$module]['icone'] ?? \Filament\Support\Icons\Heroicon::OutlinedSquares2x2" />
                        </span>
                        <div class="gr-perm-detail-texte">
                            <h3>Module {{ $courant[0] }}</h3>
                            <p>{{ $courant[1] }} · rôle « {{ $roleActif?->nom }} »</p>
                            {{-- Note courte à la place des grands bandeaux (gain de hauteur) --}}
                            @if ($roleActif?->estAdministrateur())
                                <p class="gr-perm-note">Administrateur : toutes les permissions, non modifiables.</p>
                            @elseif (! Acces::peut('acces.modifier'))
                                <p class="gr-perm-note">Consultation seule : votre rôle ne permet pas de modifier les permissions.</p>
                            @endif
                        </div>
                        @if ($modifiable)
                            <div class="gr-perm-tout">
                                <button type="button" wire:click="toutBasculer(true)" class="gr-perm-tout-oui">Tout activer</button>
                                <span class="gr-perm-tout-sep"></span>
                                <button type="button" wire:click="toutBasculer(false)" class="gr-perm-tout-non">Tout désactiver</button>
                            </div>
                        @endif
                    </header>

                    <ul class="gr-perm-actions">
                        @foreach ($courant[2] as $action)
                            @php($actif = in_array("{$module}.{$action}", $accordees, true))
                            <li class="gr-perm-action" wire:key="perm-{{ $role }}-{{ $module }}-{{ $action }}">
                                <div>
                                    <strong>{{ Acces::ACTIONS[$action] ?? $action }} — {{ $courant[0] }}</strong>
                                    <span>{{ PermissionsRoles::DESCRIPTIONS[$action] ?? '' }}</span>
                                    <code>{{ $module }}.{{ $action }}</code>
                                </div>
                                <button type="button" role="switch" aria-checked="{{ $actif ? 'true' : 'false' }}"
                                    aria-label="{{ Acces::libellePermission("{$module}.{$action}") }}"
                                    @if ($modifiable) wire:click="basculer('{{ $action }}')" @else disabled @endif
                                    @class(['gr-interrupteur', 'gr-interrupteur-on' => $actif])>
                                    <span class="gr-interrupteur-texte">{{ $actif ? 'On' : 'Off' }}</span>
                                    <span class="gr-interrupteur-rond"></span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
