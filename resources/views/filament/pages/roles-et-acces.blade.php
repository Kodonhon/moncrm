@use('App\Filament\Clusters\Parametres\Pages\RolesEtAcces')
@use('App\Filament\Clusters\Parametres\Resources\RoleResource')
@use('App\Filament\Clusters\Parametres\Resources\UtilisateurResource')
@use('App\Support\Acces')
@use('Filament\Support\Icons\Heroicon')
@php
    $installe = $this->estInstalle();
@endphp

<x-filament-panels::page>
    <div class="gr-acces">
        @include('filament.acces.entete', ['actif' => $installe ? $onglet : 'apercu'])

        @if (! $installe)
            {{-- Tables d'accès pas encore créées : tout le monde a tous les droits en attendant --}}
            <x-crm.bandeau-alerte ton="orange" titre="La gestion des rôles n'est pas encore installée">
                Tant que les tables d'accès n'existent pas, chaque compte connecté a tous les droits.
                Lancez ces deux commandes dans le terminal Laragon, dans le dossier du projet, puis rechargez la page.
            </x-crm.bandeau-alerte>

            <div class="gr-acces-install">
                <div class="gr-acces-install-etape">
                    <span class="gr-acces-install-num">1</span>
                    <div>
                        <strong>Créer les tables</strong>
                        <p>Ajoute crm_roles, crm_role_permissions, crm_role_utilisateur et quelques colonnes à users. Rien n'est supprimé.</p>
                        <code>php artisan migrate</code>
                    </div>
                </div>
                <div class="gr-acces-install-etape">
                    <span class="gr-acces-install-num">2</span>
                    <div>
                        <strong>Créer les rôles et votre compte administrateur</strong>
                        <p>Crée les rôles par défaut et donne le rôle Administrateur à votre compte et aux comptes existants sans rôle.</p>
                        <code>php artisan crm:installer-acces {{ auth()->user()?->email ?? 'votre@email' }}</code>
                    </div>
                </div>
            </div>

            <section class="gr-admin-groupe">
                <h2 class="gr-admin-groupe-titre"><span>Rôles qui seront créés</span></h2>
                <div class="gr-acces-roles">
                    @foreach (Acces::ROLES_PAR_DEFAUT as $code => [$nom, $description, $couleur, $perimetre, $permissions])
                        <div class="gr-acces-role">
                            <div class="gr-acces-role-haut">
                                <x-crm.icone-ronde :icone="Heroicon::OutlinedKey" :ton="RolesEtAcces::ton($couleur)" />
                                <div>
                                    <span class="gr-acces-role-nom">{{ $nom }}</span>
                                    <span class="gr-acces-role-perimetre">{{ Acces::PERIMETRES[$perimetre][0] ?? $perimetre }}</span>
                                </div>
                            </div>
                            <p class="gr-acces-role-texte">{{ $description }}</p>
                            <div class="gr-acces-role-pied">
                                <span>{{ $permissions === ['*'] ? 'Toutes les permissions' : count($permissions).' permissions' }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

        @elseif ($onglet === 'matrice')
            {{-- Matrice : une ligne par permission, une colonne par rôle --}}
            @php $roles = $this->getRoles(); @endphp
            <p class="gr-acces-aide">
                ● = autorisé. Cliquez sur un rôle pour modifier ses permissions.
                Le périmètre indique quelles données le rôle voit.
            </p>
            <div class="gr-acces-matrice-cadre">
                <table class="gr-acces-matrice">
                    <thead>
                        <tr>
                            <th scope="col" class="gr-acces-matrice-module">Module / action</th>
                            @foreach ($roles as $role)
                                <th scope="col">
                                    <a href="{{ RoleResource::getUrl('edit', ['record' => $role]) }}" class="gr-acces-matrice-role gr-ton-texte-{{ RolesEtAcces::ton($role->couleur) }}">
                                        {{ $role->nom }}
                                    </a>
                                    <span class="gr-acces-matrice-perimetre">{{ Acces::PERIMETRES[$role->perimetre][0] ?? $role->perimetre }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    @foreach (Acces::MODULES as $module => [$libelle, $description, $actions, $icone])
                        <tbody>
                            <tr class="gr-acces-matrice-groupe">
                                <th scope="rowgroup" colspan="{{ $roles->count() + 1 }}">
                                    <x-filament::icon :icon="constant(Heroicon::class.'::'.$icone)" class="gr-acces-matrice-icone" />
                                    {{ $libelle }} <span>{{ $description }}</span>
                                </th>
                            </tr>
                            @foreach ($actions as $action)
                                @php $permission = "{$module}.{$action}"; @endphp
                                <tr>
                                    <th scope="row" class="gr-acces-matrice-action">{{ Acces::ACTIONS[$action] ?? $action }}</th>
                                    @foreach ($roles as $role)
                                        @php $ok = $role->estAdministrateur() || $role->permissions->contains('permission', $permission); @endphp
                                        <td @class(['gr-acces-oui' => $ok, 'gr-acces-non' => ! $ok])>
                                            <span aria-label="{{ $ok ? 'Autorisé' : 'Non autorisé' }}">{{ $ok ? '●' : '—' }}</span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>

        @else
            {{-- Vue d'ensemble --}}
            <div class="gr-acces-indicateurs">
                @foreach ($this->getIndicateurs() as $i)
                    <x-crm.carte-indicateur :valeur="$i['valeur']" :libelle="$i['libelle']" :icone="$i['icone']" :ton="$i['ton']" :href="$i['href']" />
                @endforeach
            </div>

            <section class="gr-admin-groupe">
                <h2 class="gr-admin-groupe-titre"><span>Rôles</span></h2>
                <div class="gr-acces-roles">
                    @foreach ($this->getRoles() as $role)
                        <a href="{{ RoleResource::getUrl('edit', ['record' => $role]) }}" class="gr-acces-role gr-acces-role-lien">
                            <div class="gr-acces-role-haut">
                                <x-crm.icone-ronde :icone="$role->systeme ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedKey" :ton="RolesEtAcces::ton($role->couleur)" />
                                <div>
                                    <span class="gr-acces-role-nom">{{ $role->nom }}</span>
                                    <span class="gr-acces-role-perimetre">{{ Acces::PERIMETRES[$role->perimetre][0] ?? $role->perimetre }}</span>
                                </div>
                            </div>
                            @if (filled($role->description))
                                <p class="gr-acces-role-texte">{{ $role->description }}</p>
                            @endif
                            <div class="gr-acces-role-pied">
                                <span>{{ $role->estAdministrateur() ? 'Toutes les permissions' : $role->permissions_count.' / '.$this->totalPermissions().' permissions' }}</span>
                                <span>{{ $role->utilisateurs_count }} {{ \Illuminate\Support\Str::plural('utilisateur', $role->utilisateurs_count) }}</span>
                            </div>
                        </a>
                    @endforeach

                    @if ($this->peutModifier())
                        <a href="{{ RoleResource::getUrl('create') }}" class="gr-acces-role gr-acces-role-nouveau">
                            <x-filament::icon :icon="Heroicon::OutlinedPlus" class="gr-acces-role-plus" />
                            <span>Nouveau rôle</span>
                        </a>
                    @endif
                </div>
            </section>

            <section class="gr-admin-groupe">
                <h2 class="gr-admin-groupe-titre"><span>Dernières connexions</span></h2>
                @php $connexions = $this->getDernieresConnexions(); @endphp
                @if ($connexions->isEmpty())
                    <x-crm.etat-vide :icone="Heroicon::OutlinedClock" titre="Aucune connexion enregistrée">
                        Les connexions apparaîtront ici après la prochaine ouverture de session.
                    </x-crm.etat-vide>
                @else
                    <ul class="gr-acces-connexions">
                        @foreach ($connexions as $u)
                            <li>
                                <a href="{{ UtilisateurResource::getUrl('edit', ['record' => $u]) }}">
                                    <span class="gr-acces-connexion-nom">{{ $u->name }}</span>
                                    <span class="gr-acces-connexion-roles">
                                        @forelse ($u->roles as $r)
                                            <span class="gr-acces-pastille gr-ton-pale-{{ RolesEtAcces::ton($r->couleur) }} gr-ton-texte-{{ RolesEtAcces::ton($r->couleur) }}">{{ $r->nom }}</span>
                                        @empty
                                            <span class="gr-acces-pastille gr-ton-pale-orange gr-ton-texte-orange">Sans rôle</span>
                                        @endforelse
                                    </span>
                                    <span class="gr-acces-connexion-date">{{ $u->derniere_connexion?->diffForHumans() }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
