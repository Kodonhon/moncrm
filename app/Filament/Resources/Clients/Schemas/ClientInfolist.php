<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Filament\Resources\Clients\Actions\ActionsClient;
use App\Filament\Resources\Clients\Actions\KycActions;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Formulaires\ConstructeurFiche;
use App\Models\Agent;
use App\Models\CanalVente;
use App\Models\Client;
use App\Models\Contrat;
use App\Models\CreditScoring;
use App\Models\Entite;
use App\Models\Kyc;
use App\Models\Genre;
use App\Models\Localite;
use App\Models\PaiementContrat;
use App\Models\Produit;
use App\Models\TypePiece;
use App\Models\TypeVente;
use App\Support\CacheReferences;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;

use function Filament\Support\generate_icon_html;

/**
 * Fiche client, sur le modèle du « compte » SunCulture :
 *   1. bandeau « Prochaine action » avec le bouton utile (KYC, validation crédit) ;
 *   2. « Résumé du compte » : pastilles d'état (deposit, KYC), menu « Actions », une information par case ;
 *   3. « Parcours client » : frise verticale, de la création du compte à l'installation ;
 *   4. onglets (KYC, autres informations, historique).
 * Les dimensionnements et les contrats restent dans les gestionnaires de relations, sous la fiche.
 *
 * Ancienne version sauvegardée dans storage/app/sauvegarde-design/fiche-sunculture/ClientInfolist.php.
 */
class ClientInfolist
{
    /** Informations affichées dans l'en-tête et le résumé, donc pas répétées dans l'onglet « Autres informations ». */
    private const DANS_LA_CARTE = [
        'nom_client', 'prenoms_client', 'contact1_client', 'contact2_client', 'id_localite_commune', 'indication_site_client',
        'id_canal_vente', 'id_src_client', 'date_client', 'visibilite_client', 'entite_id_client', 'id_agent', 'genre_client', 'id_type_piece_id',
    ];

    private const COULEURS_AVATAR = ['#5AAA3C', '#3E3765', '#E8752B', '#4E9F3D', '#2B2747', '#B4530F'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 12])
            ->components([
                self::banniere()->columnSpanFull(),
                self::resumeCompte()->columnSpan(['default' => 1, 'lg' => 8]),
                self::paiements()->columnSpan(['default' => 1, 'lg' => 4]),
                self::onglets()->columnSpanFull(),
            ]);
    }

    /* ---------------------------------------------------------------------------------------------
     | Aperçu des paiements (base des ventes) : total payé et dépôt, tous contrats UPYA du client
     * ------------------------------------------------------------------------------------------- */

    private static function paiements(): Section
    {
        return Section::make('Aperçu des paiements')
            ->description('Deposits des contrats UPYA du client (base des ventes)')
            ->key('paiements-client')
            ->extraAttributes(['class' => 'gr-paiements'])
            ->schema([
                TextEntry::make('paiements')
                    ->hiddenLabel()
                    ->state(fn (Client $record) => self::contenuPaiements($record)),
            ]);
    }

    private static function contenuPaiements(Client $client): HtmlString
    {
        $deposits = PaiementContrat::verificationActive()
            ? collect($client->numerosUpya())->map(fn ($n) => PaiementContrat::depositDe($n))->filter()
            : collect();
        $nbContrats = Contrat::tableDisponible() ? $client->contrats()->count() : 0;
        $montant = fn ($v) => 'F CFA '.number_format((float) $v, 0, ',', ' ');

        $infos = '<dl class="gr-compte-grille">'
            .'<div><dt>'.self::icone(Heroicon::OutlinedDocumentText).'Contrats</dt><dd>'.$nbContrats.'</dd></div>'
            .'<div><dt>'.self::icone(Heroicon::OutlinedHashtag).'Contrat UPYA</dt><dd>'
            .(($n = $client->num_contrat_upya_actuel) ? '<span class="gr-mono">'.e($n).'</span>' : '<span class="gr-vide-valeur">—</span>').'</dd></div>'
            .'</dl>';

        if (! PaiementContrat::verificationActive()) {
            return new HtmlString($infos.'<p class="gr-vide-valeur">Base des ventes non joignable : paiements indisponibles.</p>');
        }

        return new HtmlString($infos.'<div class="gr-tuiles">'
            .'<div class="gr-tuile gr-tuile-violet"><span class="gr-tuile-libelle">'.self::icone(Heroicon::OutlinedBanknotes).'Dépôt initial</span>'
            .'<span class="gr-tuile-valeur">'.$montant($deposits->sum('mt_deposit')).'</span></div>'
            .'<div class="gr-tuile gr-tuile-vert"><span class="gr-tuile-libelle">'.self::icone(Heroicon::OutlinedGift).'Total payé</span>'
            .'<span class="gr-tuile-valeur">'.$montant($deposits->sum('total_paye')).'</span></div>'
            .'</div>');
    }

    /** Journal du client : du plus récent au plus ancien, avec le détail « champ : avant → après ». */
    private static function journal(Client $client): HtmlString
    {
        $lignes = \App\Models\JournalClient::where('id_client', $client->id_client)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();

        if ($lignes->isEmpty()) {
            return new HtmlString('<p class="gr-vide-valeur">Aucune action enregistrée pour ce client depuis l\'activation du journal.</p>');
        }

        $tons = ['cree' => 'succes', 'modifie' => 'attention', 'supprime' => 'danger'];

        return new HtmlString('<ol class="gr-journal">'.$lignes->map(function (\App\Models\JournalClient $l) use ($tons) {
            $details = collect($l->changements ?? [])->map(fn ($valeurs, $champ) => '<li><span class="gr-journal-champ">'.e($champ).'</span> '
                .($l->action === 'cree'
                    ? '<span class="gr-journal-apres">'.e($valeurs[1] ?? '—').'</span>'
                    : '<span class="gr-journal-avant">'.e($valeurs[0] ?? 'vide').'</span> → <span class="gr-journal-apres">'.e($valeurs[1] ?? 'vide').'</span>')
                .'</li>')->implode('');

            return '<li class="gr-journal-ligne">'
                .'<div class="gr-journal-tete">'
                .self::pastille(\App\Models\JournalClient::ACTIONS[$l->action] ?? $l->action, $tons[$l->action] ?? null)
                .'<b>'.e(\App\Models\JournalClient::OBJETS[$l->objet] ?? $l->objet).'</b>'
                .'<span class="gr-mono gr-journal-id">'.e($l->objet_id).'</span>'
                .'<span class="gr-journal-qui">'.e($l->nom_utilisateur ?? 'Système').' · '.e($l->created_at?->format('d/m/Y H:i')).'</span>'
                .'</div>'
                .($details ? '<ul class="gr-journal-details">'.$details.'</ul>' : '')
                .'</li>';
        })->implode('').'</ol>');
    }

    /** Cartes des contrats du client (onglet « Contrats ») : un clic ouvre la page du contrat. */
    private static function cartesContrats(Client $client): HtmlString
    {
        $contrats = $client->contrats()->latest('date_contrat')->get();

        if ($contrats->isEmpty()) {
            return new HtmlString('<p class="gr-vide-valeur">Aucun contrat. Il se crée dans « Contrats », plus bas, à partir d\'un dimensionnement validé et du KYC.</p>');
        }

        return new HtmlString('<div class="gr-cartes-contrats">'.$contrats->map(function (Contrat $contrat) {
            $deposit = Contrat::upyaDisponible() && filled($contrat->num_contrat_upya) && PaiementContrat::verificationActive()
                ? PaiementContrat::depositDe($contrat->num_contrat_upya) : null;
            $produit = CacheReferences::libelle(Produit::class, $contrat->id_prod) ?? 'Produit non renseigné';

            return '<a class="gr-carte-contrat" href="'.e(\App\Filament\Resources\Contrats\ContratResource::getUrl('view', ['record' => $contrat])).'">'
                .'<span class="gr-carte-contrat-num">'.e($contrat->num_contrat).'</span>'
                .'<span class="gr-carte-contrat-fleche">'.self::icone(Heroicon::ArrowRight).'</span>'
                .'<strong>'.e($produit).'</strong>'
                .'<small>'.e(collect([
                    filled($contrat->num_contrat_upya ?? null) ? 'UPYA '.$contrat->num_contrat_upya : null,
                    $contrat->date_contrat ? 'signé le '.$contrat->date_contrat->format('d/m/Y') : null,
                ])->filter()->implode(' · ')).'</small>'
                .'<span class="gr-carte-contrat-pastilles">'
                .(($type = CacheReferences::libelle(TypeVente::class, $contrat->id_type_vente)) ? self::pastille($type, 'attention') : '')
                .match ($deposit['statut'] ?? null) {
                    'full_deposit' => self::pastille('Dépôt complet', 'succes'),
                    'partial_deposit' => self::pastille('Dépôt partiel', 'attention'),
                    default => filled($contrat->num_contrat_upya ?? null) ? self::pastille('Aucun deposit') : '',
                }
                .self::pastille(Contrat::STATUTS[$contrat->statut] ?? $contrat->statut, $contrat->statut === 'actif' ? 'succes' : 'danger')
                .'</span></a>';
        })->implode('').'</div>');
    }

    /* ---------------------------------------------------------------------------------------------
     | 1. Bandeau « Prochaine action » (comme le bandeau d'information de SunCulture)
     * ------------------------------------------------------------------------------------------- */

    private static function banniere(): Section
    {
        return Section::make()
            ->key('prochaine-action')
            ->extraAttributes(['class' => 'gr-banniere'])
            ->schema([
                Flex::make([
                    TextEntry::make('prochaine_action')
                        ->hiddenLabel()
                        ->state(fn (Client $record) => self::prochaineAction($record)),
                    Actions::make([
                        KycActions::ajouter()->label('Ajouter le KYC'),
                        KycActions::modifier()
                            ->label(fn (Client $record) => $record->kyc?->etatPiece()['etat'] === 'expiree' ? 'Mettre à jour le KYC' : 'Corriger le KYC')
                            ->color(fn (Client $record) => $record->kyc?->etatPiece()['etat'] === 'expiree' ? 'warning' : 'danger')
                            ->visible(fn (Client $record) => $record->kyc?->etatPiece()['etat'] === 'expiree'
                                || (CreditScoring::tableDisponible() && (int) $record->kyc?->statut_validation_kyc === Kyc::VALIDATION_A_CORRIGER)),
                        KycActions::validationCredit(),
                    ])->grow(false),
                ])->from('md')->verticallyAlignCenter(),
            ]);
    }

    /* ---------------------------------------------------------------------------------------------
     | 2. Résumé du compte : pastilles d'état, menu « Actions », une information par case
     * ------------------------------------------------------------------------------------------- */

    private static function resumeCompte(): Section
    {
        return Section::make(fn (Client $record) => new HtmlString('<span class="gr-compte-titre">Informations du profil'
                .implode('', array_filter([self::pastilleDeposit($record), self::pastilleKyc($record)])).'</span>'))
            ->key('resume-client')
            ->extraAttributes(['class' => 'gr-compte'])
            ->afterHeader([
                Action::make('appelerEntete')
                    ->label('Appeler')
                    ->tooltip('Appeler')
                    ->icon(Heroicon::OutlinedPhone)
                    ->iconButton()
                    ->color('gray')
                    ->url(fn (Client $record) => 'tel:+'.self::chiffres($record->contact1_client))
                    ->visible(fn (Client $record) => filled($record->contact1_client)),
                Action::make('whatsappEntete')
                    ->label('WhatsApp')
                    ->tooltip('WhatsApp')
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->iconButton()
                    ->color('success')
                    ->url(fn (Client $record) => 'https://wa.me/'.self::chiffres($record->contact1_client), shouldOpenInNewTab: true)
                    ->visible(fn (Client $record) => filled($record->contact1_client)),
                Action::make('modifierEntete')
                    ->authorize(fn () => \App\Support\Acces::peut('clients.modifier'))
                    ->label('Modifier')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->outlined()
                    ->url(fn (Client $record) => ClientResource::getUrl('edit', ['record' => $record])),
                ActionsClient::menu(),
            ])
            ->schema([
                TextEntry::make('resume')
                    ->hiddenLabel()
                    ->state(fn (Client $record) => self::champsCompte($record)),
            ]);
    }

    /** Pastille ronde aux initiales du client, couleur stable d'après son nom (fiche et liste). */
    public static function avatar(Client $client, string $classe = 'gr-ent-avatar'): string
    {
        $couleur = self::COULEURS_AVATAR[crc32($client->nom_complet) % count(self::COULEURS_AVATAR)];
        $initiales = collect(preg_split('/\s+/u', self::casseTitre($client->nom_complet)))->filter()->take(2)
            ->map(fn ($mot) => mb_strtoupper(mb_substr($mot, 0, 1)))->implode('');

        return '<span class="'.e($classe).'" style="--c: '.$couleur.'" aria-hidden="true">'.e($initiales ?: '?').'</span>';
    }

    public static function pastilleDeposit(Client $client): ?string
    {
        $deposit = PaiementContrat::verificationActive() ? $client->deposit() : null;

        return match ($deposit['statut'] ?? null) {
            'full_deposit' => self::pastille('Dépôt complet', 'succes', point: true),
            'partial_deposit' => self::pastille('Dépôt partiel', 'attention', point: true),
            default => $client->visibilite_client === false ? self::pastille('Désactivé', 'danger', point: true) : null,
        };
    }

    public static function pastilleKyc(Client $client): ?string
    {
        $kyc = $client->kyc;

        $credit = CreditScoring::tableDisponible();

        return match (true) {
            ! $kyc => self::pastille('KYC à faire', 'attention'),
            $kyc->etatPiece()['etat'] === 'expiree' => self::pastille('Pièce expirée', 'danger'),
            $credit && (int) $kyc->statut_validation_kyc === Kyc::VALIDATION_A_CORRIGER => self::pastille('KYC à corriger', 'danger'),
            $credit && ! $kyc->estValideParLeCredit() => self::pastille('KYC à valider (crédit)', 'attention'),
            $kyc->etatPiece()['etat'] === 'bientot' => self::pastille('Pièce bientôt expirée', 'attention'),
            default => self::pastille($credit ? 'KYC validé' : 'KYC valable', 'succes'),
        };
    }

    /**
     * Grille d'informations, une par case : [icône, libellé, valeur HTML déjà échappée, pleine largeur ?].
     * Sans contrat : profil du client. Avec un contrat : résumé du contrat (titulaire cliquable vers la fiche client).
     */
    public static function champsCompte(Client $client, ?Contrat $contratAffiche = null): HtmlString
    {
        $localite = $client->ref(Localite::class, 'id_localite_commune');
        $agent = $client->ref(Agent::class, 'id_agent');
        $contrat = $contratAffiche;
        $upya = $contrat && Contrat::upyaDisponible() ? $contrat->num_contrat_upya : null;
        $deposit = $upya && PaiementContrat::verificationActive() ? PaiementContrat::depositDe($upya) : null;
        $credit = CreditScoring::tableDisponible() ? $client->etatCredit() : null;
        $produit = $contrat?->id_prod;

        $telephone = fn (?string $numero) => $numero
            ? '<a class="gr-lien-pointille" href="tel:+'.self::chiffres($numero).'">'.e(ClientsTable::formaterTelephone($numero)).'</a>'
            : null;
        $creditTon = match ($credit) {
            'valide' => 'succes',
            'kyc_a_corriger' => 'danger',
            null, 'attente_terrain' => null,
            default => 'attention',
        };

        $nom = self::avatar($client, 'gr-ent-avatar gr-compte-avatar').e(self::casseTitre($client->nom_complet));

        if ($contrat) {
            return self::grille([
                // Le nom du titulaire ouvre la fiche du client
                [Heroicon::OutlinedUser, 'Titulaire', '<a class="gr-compte-nom gr-compte-lien" href="'.e(ClientResource::getUrl('view', ['record' => $client])).'">'
                    .self::icone(Heroicon::OutlinedLink).$nom.'</a>'],
                [Heroicon::OutlinedHashtag, 'N° de contrat', '<span class="gr-mono">'.e($contrat->num_contrat).'</span>'],
                [Heroicon::OutlinedCreditCard, 'Type de vente', e(CacheReferences::libelle(TypeVente::class, $contrat->id_type_vente))],
                [Heroicon::OutlinedShieldCheck, 'Credit check', $credit ? self::pastille(Client::CREDIT_ETATS[$credit] ?? $credit, $creditTon) : null],
                [Heroicon::OutlinedBanknotes, 'Total payé', $deposit ? '<span class="gr-compte-montant">XOF '.e(number_format((float) $deposit['total_paye'], 0, ',', ' ')).'</span>' : null],
                [Heroicon::OutlinedCube, 'Produit', e(CacheReferences::libelle(Produit::class, $produit))],
                [Heroicon::OutlinedPhone, 'Téléphone', $telephone($client->contact1_client)],
                [Heroicon::OutlinedIdentification, 'Pièce d\'identité', e($client->kyc?->num_piece_id)],
                [Heroicon::OutlinedUserCircle, 'Agent commercial', e(CacheReferences::libelle(Agent::class, $contrat->id_agent ?? $client->id_agent))],
                [Heroicon::OutlinedPhone, 'Téléphone de l\'agent', $telephone(\App\Models\Agent::find($contrat->id_agent ?? $client->id_agent)?->contact_agent)],
                [Heroicon::OutlinedDocumentText, 'Contrat UPYA', $upya ? '<span class="gr-mono">'.e($upya).'</span>' : null],
                [Heroicon::OutlinedCurrencyDollar, 'Montant du contrat', filled($contrat->montant) ? e(number_format((float) $contrat->montant, 0, ',', ' ')).' F CFA' : null],
                [Heroicon::OutlinedCalendarDays, 'Signé le', $contrat->date_contrat ? e($contrat->date_contrat->format('d/m/Y')) : null],
                [Heroicon::OutlinedCheckBadge, 'Statut', self::pastille(Contrat::STATUTS[$contrat->statut] ?? $contrat->statut, $contrat->statut === 'actif' ? 'succes' : 'danger')],
            ]);
        }

        $champs = [
            [Heroicon::OutlinedUser, 'Nom', '<span class="gr-compte-nom">'.$nom.'</span>'],
            [Heroicon::OutlinedHashtag, 'N° client', '<span class="gr-mono">'.e($client->id_client).'</span>'],
            [Heroicon::OutlinedPhone, 'Téléphone', $telephone($client->contact1_client)],
            [Heroicon::OutlinedIdentification, 'Pièce d\'identité', e(collect([
                CacheReferences::libelle(TypePiece::class, $client->kyc?->type_piece_id ?? $client->id_type_piece_id),
                $client->kyc?->num_piece_id,
            ])->filter()->implode(' · '))],
            [Heroicon::OutlinedUserCircle, 'Agent commercial', $agent
                ? e(CacheReferences::libelle(Agent::class, $client->id_agent))
                : '<a class="gr-lien-action" href="'.e(ClientResource::getUrl('edit', ['record' => $client])).'">Assigner un agent</a>'],
            [Heroicon::OutlinedPhone, 'Téléphone de l\'agent', $telephone($agent?->contact_agent)],
            [Heroicon::OutlinedMapPin, 'Localité', $localite
                ? ($localite->gps_localite
                    ? '<a class="gr-lien-discret" href="https://www.google.com/maps?q='.e($localite->gps_localite).'" target="_blank" rel="noopener">'.e(self::casseTitre($localite->lib_localite)).'</a>'
                    : e(self::casseTitre($localite->lib_localite)))
                : null],
            [Heroicon::OutlinedBuildingStorefront, 'Ville et shop', e(self::casseTitre((string) $client->ville_et_shop))],
            [Heroicon::OutlinedMegaphone, 'Canal de vente', e($client->ref(CanalVente::class, 'id_canal_vente')?->lib_canal_vente)],
            [Heroicon::OutlinedShieldCheck, 'Credit check', $credit ? self::pastille(Client::CREDIT_ETATS[$credit] ?? $credit, $creditTon) : null],
            [Heroicon::OutlinedBuildingOffice2, 'Entité', e(CacheReferences::libelle(Entite::class, $client->entite_id_client))],
            [Heroicon::OutlinedCalendarDays, 'Enregistré le', $client->date_client ? e($client->date_client->format('d/m/Y')).' <span class="gr-dl-sous">'.e($client->date_client->diffForHumans()).'</span>' : null],
            [Heroicon::OutlinedMap, 'Indication du site', e(self::casseTitre((string) $client->indication_site_client)), true],
        ];

        return self::grille($champs);
    }

    /** @param  array<int, array{0: Heroicon, 1: string, 2: ?string, 3?: bool}>  $champs */
    private static function grille(array $champs): HtmlString
    {
        return new HtmlString('<dl class="gr-compte-grille">'.collect($champs)->map(fn ($c) => '<div'.(($c[3] ?? false) ? ' class="gr-compte-plein"' : '').'>'
            .'<dt>'.self::icone($c[0]).e($c[1]).'</dt>'
            .'<dd>'.(filled($c[2]) ? $c[2] : '<span class="gr-vide-valeur">—</span>').'</dd>'
            .'</div>')->implode('').'</dl>');
    }

    /* ---------------------------------------------------------------------------------------------
     | 3. Parcours client : frise verticale, une étape en cours
     * ------------------------------------------------------------------------------------------- */

    public static function frise(Client $client, ?Contrat $contratAffiche = null): HtmlString
    {
        $kyc = $client->kyc;
        $evaluations = $client->evalSites;
        $nbEval = $evaluations->count();
        $validees = $evaluations->filter(fn ($evaluation) => $evaluation->estValidee())->count();
        // Sur la page d'un contrat : ce contrat et son deposit ; sur la fiche client : le premier contrat, le meilleur deposit
        $contrat = $contratAffiche ?? (Contrat::tableDisponible() ? $client->contrats()->oldest('date_contrat')->first() : null);
        $deposit = match (true) {
            ! PaiementContrat::verificationActive() => null,
            $contratAffiche !== null => Contrat::upyaDisponible() && filled($contratAffiche->num_contrat_upya)
                ? PaiementContrat::depositDe($contratAffiche->num_contrat_upya) : null,
            default => $client->deposit(),
        };
        $scoring = CreditScoring::tableDisponible()
            ? $client->creditScorings()->where(CreditScoring::CHAMP_DECISION, CreditScoring::DECISION_VALIDEE)->latest('date_cs')->first()
            : null;
        $installation = self::installation($client);
        $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y') : null;

        // [titre, description, date si faite, faite ?]
        $etapes = [
            ['Compte créé', 'Client enregistré dans le CRM', $date($client->date_client), true],
            ['KYC', $kyc?->etatPiece()['etat'] === 'expiree' ? 'Pièce expirée' : 'Pièce, photos, contact', $date($kyc?->date_kyc), $kyc && $kyc->etatPiece()['etat'] !== 'expiree'],
            ['Dimensionnement', $nbEval
                ? $nbEval.' dimensionnement'.($nbEval > 1 ? 's' : '').' · '.$validees.' / '.$nbEval.' validé'.($validees > 1 ? 's' : '')
                : 'Un par kit, validé', $date($evaluations->min('date_eval_site')), $nbEval > 0 && $validees === $nbEval],
            ['Contrat', $contrat ? 'Contrat '.$contrat->num_contrat : 'Dimensionnement validé + KYC', $date($contrat?->date_contrat), (bool) $contrat],
            ['Deposit', match ($deposit['statut'] ?? null) {
                'full_deposit' => 'Dépôt complet reçu',
                'partial_deposit' => 'Dépôt partiel : en attente du complément',
                default => 'Dépôt initial sur le contrat UPYA',
            }, $date($deposit['date_deposit'] ?? null), ($deposit['statut'] ?? null) === 'full_deposit'],
            ['Credit check', $scoring ? 'Validé par le crédit' : 'Validation du KYC et credit scoring', $date($scoring?->date_cs), (bool) $scoring],
            ['Installation', $installation?->assignee_a ? 'Par '.$installation->assignee_a : 'Système installé chez le client', $date($installation?->date_installation), (bool) $installation],
        ];

        // Système installé : tout le parcours est fait. Sinon l'étape en cours est la première non faite.
        $courante = $installation ? null : collect($etapes)->search(fn ($etape) => ! $etape[3]);

        return new HtmlString('<ol class="gr-frise" aria-label="Parcours client">'.collect($etapes)->map(function ($etape, $i) use ($courante, $installation) {
            $etat = $etape[3] || $installation ? 'faite' : ($i === $courante ? 'courante' : 'avenir');

            return '<li class="gr-frise-etape gr-frise-'.$etat.'"'.($etat === 'courante' ? ' aria-current="step"' : '').'>'
                .'<span class="gr-frise-point">'.($etat === 'faite' ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 12 4 4 8-8"/></svg>' : '').'</span>'
                .'<div class="gr-frise-corps">'
                .'<div class="gr-frise-ligne"><b>'.e($etape[0]).'</b>'.($etape[2] && $etat === 'faite' ? '<span class="gr-frise-date">'.e($etape[2]).'</span>' : '').'</div>'
                .'<small>'.e($etape[1]).'</small>'
                .match ($etat) {
                    'courante' => '<span class="gr-frise-statut gr-frise-statut-courante">Étape actuelle</span>',
                    'avenir' => '<span class="gr-frise-statut">À venir</span>',
                    default => '',
                }
                .'</div></li>';
        })->implode('').'</ol>');
    }

    /* ---------------------------------------------------------------------------------------------
     | 3b. Onglets : KYC, autres informations, historique
     * ------------------------------------------------------------------------------------------- */

    private static function onglets(): Tabs
    {
        return Tabs::make('Détails')
            ->key('onglets-client', isInheritable: false)
            ->extraAttributes(['class' => 'gr-onglets-fiche'])
            ->tabs([
                // Contrats du client en cartes (comme « Customer Accounts ») : un clic ouvre le contrat
                Tab::make('Contrats')
                    ->key('onglet-contrats', isInheritable: false)
                    ->badge(fn (Client $record) => $record->contrats()->count())
                    ->badgeColor('success')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn () => Contrat::tableDisponible() && \App\Support\Acces::peut('contrats.voir'))
                    ->schema([
                        TextEntry::make('cartes_contrats')
                            ->hiddenLabel()
                            ->state(fn (Client $record) => self::cartesContrats($record)),
                    ]),
                Tab::make('Parcours')
                    ->key('onglet-parcours', isInheritable: false)
                    ->icon(Heroicon::OutlinedMap)
                    ->schema([
                        TextEntry::make('parcours')
                            ->hiddenLabel()
                            ->state(fn (Client $record) => self::frise($record)),
                    ]),
                Tab::make('KYC')
                    ->visible(fn () => \App\Support\Acces::peut('kyc.voir'))
                    ->key('onglet-kyc', isInheritable: false)
                    ->icon(Heroicon::OutlinedIdentification)
                    ->badge(fn (Client $record) => match (true) {
                        ! $record->kyc => 'À faire',
                        ! CreditScoring::tableDisponible() => null,
                        (int) $record->kyc->statut_validation_kyc === Kyc::VALIDATION_A_CORRIGER => 'À corriger',
                        ! $record->kyc->estValideParLeCredit() => 'À valider',
                        default => null,
                    })
                    ->badgeColor(fn (Client $record) => (int) $record->kyc?->statut_validation_kyc === Kyc::VALIDATION_A_CORRIGER ? 'danger' : 'warning')
                    ->schema([self::sectionKyc()]),
                Tab::make('Autres informations')
                    ->key('onglet-informations', isInheritable: false)
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->schema([
                        Group::make((new ConstructeurFiche('client', self::DANS_LA_CARTE))->compacte()->sections())
                            ->columns(['default' => 1, 'lg' => 2]),
                    ]),
                // Journal : toutes les modifications et actions sur le client et ses dossiers, avec l'auteur
                Tab::make('Journal')
                    ->key('onglet-journal', isInheritable: false)
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->visible(fn () => \App\Models\JournalClient::tableDisponible())
                    ->badge(fn (Client $record) => \App\Models\JournalClient::where('id_client', $record->id_client)->count() ?: null)
                    ->badgeColor('gray')
                    ->schema([
                        TextEntry::make('journal')
                            ->hiddenLabel()
                            ->state(fn (Client $record) => self::journal($record)),
                    ]),
                Tab::make('Historique')
                    ->key('onglet-historique', isInheritable: false)
                    ->icon(Heroicon::OutlinedClock)
                    ->schema([
                        TextEntry::make('historique')
                            ->hiddenLabel()
                            ->state(fn (Client $record) => self::flux(self::evenements($record))),
                    ]),
            ]);
    }

    /** KYC : contenu s'il existe, sinon un état vide qui explique quoi préparer. */
    private static function sectionKyc(): Section
    {
        return Section::make('KYC')
            ->key('section-kyc')
            ->icon(Heroicon::OutlinedIdentification)
            ->compact()
            ->description(fn (Client $record) => $record->kyc
                ? ($record->kyc->date_kyc ? 'Réalisé le '.$record->kyc->date_kyc->format('d/m/Y') : 'Date non renseignée')
                    .(($agent = CacheReferences::libelle(Agent::class, $record->kyc->id_agent)) ? ' par '.$agent : '')
                : null)
            ->afterHeader([
                KycActions::validationCredit(),
                KycActions::modifier(),
            ])
            ->schema([
                TextEntry::make('kyc_a_corriger')
                    ->hiddenLabel()
                    ->state(fn (Client $record) => self::alerteKycACorriger($record))
                    ->visible(fn (Client $record) => CreditScoring::tableDisponible()
                        && (int) $record->kyc?->statut_validation_kyc === Kyc::VALIDATION_A_CORRIGER),
                TextEntry::make('kyc_vide')
                    ->hiddenLabel()
                    ->state(fn () => self::etatVideKyc())
                    ->visible(fn (Client $record) => ! $record->kyc),
                Actions::make([
                    KycActions::ajouter()->label('Démarrer le KYC')->color('gray')->outlined(),
                ])->alignCenter()
                    ->visible(fn (Client $record) => ! $record->kyc),
                Flex::make([
                    TextEntry::make('statut_kyc')
                        ->hiddenLabel()
                        ->state('KYC fait')
                        ->badge()
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->grow(false),
                    TextEntry::make('validite_piece')
                        ->hiddenLabel()
                        ->state(fn (Client $record) => $record->kyc?->etatPiece()['libelle'])
                        ->badge()
                        ->icon(fn (Client $record) => match ($record->kyc?->etatPiece()['etat']) {
                            'valide' => Heroicon::OutlinedCheckBadge,
                            'expiree' => Heroicon::OutlinedExclamationTriangle,
                            'bientot' => Heroicon::OutlinedClock,
                            default => Heroicon::OutlinedQuestionMarkCircle,
                        })
                        ->color(fn (Client $record) => $record->kyc?->etatPiece()['couleur'] ?? 'gray')
                        ->grow(false),
                    TextEntry::make('age_client')
                        ->hiddenLabel()
                        ->state(fn (Client $record) => match (true) {
                            $record->kyc?->age() === null => 'Âge inconnu',
                            $record->kyc->ageValide() => $record->kyc->age().' ans',
                            default => $record->kyc->age().' ans · hors limites ('.Kyc::AGE_MIN.'–'.Kyc::AGE_MAX.' ans)',
                        })
                        ->badge()
                        ->icon(Heroicon::OutlinedCake)
                        ->color(fn (Client $record) => $record->kyc?->ageValide() ? 'gray' : 'danger')
                        ->grow(false),
                    TextEntry::make('validation_credit_kyc')
                        ->hiddenLabel()
                        ->state(fn (Client $record) => $record->kyc?->etatValidation()['libelle'])
                        ->badge()
                        ->icon(fn (Client $record) => match ($record->kyc?->etatValidation()['etat']) {
                            'valide' => Heroicon::OutlinedShieldCheck,
                            'a_corriger' => Heroicon::OutlinedArrowUturnLeft,
                            default => Heroicon::OutlinedClock,
                        })
                        ->color(fn (Client $record) => $record->kyc?->etatValidation()['couleur'] ?? 'gray')
                        ->tooltip(fn (Client $record) => $record->kyc?->date_validation_kyc
                            ? 'Le '.$record->kyc->date_validation_kyc->format('d/m/Y')
                                .(($agent = CacheReferences::libelle(Agent::class, $record->kyc->id_agent_validation_kyc)) ? ' par '.$agent : '')
                            : null)
                        ->visible(fn () => CreditScoring::tableDisponible())
                        ->grow(false),
                ])->extraAttributes(['class' => 'gr-badges-kyc'])
                    ->visible(fn (Client $record) => (bool) $record->kyc),
                Group::make((new ConstructeurFiche('kyc', relation: 'kyc'))->compacte(colonnes: 3, hauteurPhotos: 110)->sections())
                    ->columns(['default' => 1, 'lg' => 2])
                    ->visible(fn (Client $record) => (bool) $record->kyc),
            ]);
    }

    /** KYC renvoyé par le crédit : ce qu'il faut corriger, en évidence pour l'agent terrain. */
    private static function alerteKycACorriger(Client $client): HtmlString
    {
        $kyc = $client->kyc;
        $par = collect([
            $kyc->date_validation_kyc?->format('d/m/Y'),
            CacheReferences::libelle(Agent::class, $kyc->id_agent_validation_kyc),
        ])->filter()->implode(' · ');

        return new HtmlString('<div class="gr-prochaine-bloc gr-ton-danger">'
            .'<span class="gr-surtitre">Renvoyé par le crédit'.($par ? ' · '.e($par) : '').'</span>'
            .'<h4>KYC à corriger</h4>'
            .'<p>'.nl2br(e($kyc->motif_validation_kyc ?: 'Motif non précisé.')).'</p>'
            .'<p><small>Corrigez avec « Modifier le KYC » : il repartira au crédit pour validation.</small></p></div>');
    }

    private static function etatVideKyc(): HtmlString
    {
        $elements = [
            [Heroicon::OutlinedIdentification, 'Pièce d\'identité (recto / verso)'],
            [Heroicon::OutlinedCamera, 'Photos du client et du site'],
            [Heroicon::OutlinedUser, 'Personne à contacter'],
        ];

        return new HtmlString('<div class="gr-etat-vide">'
            .'<span class="gr-etat-vide-icone">'.self::icone(Heroicon::OutlinedIdentification, IconSize::ExtraLarge).'</span>'
            .'<h4>KYC à réaliser</h4>'
            .'<p>Le KYC est requis pour créer un contrat. Il se fait une seule fois par client, quel que soit le nombre de kits.</p>'
            .'<ul class="gr-liste-pieces">'.collect($elements)->map(fn ($el) => '<li>'.self::icone($el[0]).e($el[1]).'</li>')->implode('').'</ul>'
            .'</div>');
    }


    public static function prochaineAction(Client $client): HtmlString
    {
        $kyc = $client->kyc;
        $evaluations = $client->evalSites;
        $aValider = $evaluations->reject(fn ($evaluation) => $evaluation->estValidee())->count();
        $attente = $client->date_client ? (int) $client->date_client->diffInDays(now()) : null;
        $credit = CreditScoring::tableDisponible() ? $client->etatCredit() : null;

        [$titre, $detail, $ton] = match (true) {
            $client->visibilite_client === false => ['Client désactivé', 'Ce client est masqué de la liste principale. Réactivez-le depuis le menu « ⋯ ».', 'danger'],
            ($installation = self::installation($client)) !== null => ['Système installé',
                'Installé'.($installation->date_installation ? ' le <b>'.$installation->date_installation->format('d/m/Y').'</b>' : '')
                    .($installation->assignee_a ? ' par '.e($installation->assignee_a) : '').'. Plus aucune action en attente.', 'succes'],
            ! $kyc => ['Réaliser le KYC', $attente !== null ? 'Client en attente depuis <b>'.$attente.' jour'.($attente > 1 ? 's' : '').'</b>.' : 'Le KYC est requis pour créer un contrat.', 'attention'],
            $kyc->etatPiece()['etat'] === 'expiree' => ['Mettre à jour le KYC', e($kyc->etatPiece()['libelle']).'.', 'danger'],
            $evaluations->isEmpty() => ['Faire un dimensionnement', 'Faites le dimensionnement du kit dans « Dimensionnements », plus bas.', 'info'],
            $credit === 'kyc_a_corriger' => ['Corriger le KYC', 'Renvoyé par le crédit : '.e($kyc->motif_validation_kyc ?: 'motif non précisé').'.', 'danger'],
            $aValider > 0 => ['Valider '.$aValider.' dimensionnement'.($aValider > 1 ? 's' : ''), 'Validez-les dans « Dimensionnements », plus bas.', 'info'],
            Contrat::tableDisponible() && $client->peutCreerContrat() => ['Créer le contrat', 'Un dimensionnement validé et un KYC valable sont prêts. Créez-le dans « Contrats », plus bas.', 'succes'],
            // Dossier prêt mais table des contrats pas encore créée : on le dit au lieu de « Rien en attente »
            ! Contrat::tableDisponible() && $kyc->estValable() && $evaluations->contains(fn ($evaluation) => $evaluation->estValidee()) => [
                'Créer le contrat',
                'Le dossier est prêt, mais les contrats ne sont pas encore activés dans la base : exécutez le script <b>database/sql/contrat_regles.sql</b> dans Supabase (SQL Editor), puis rechargez la page. Le bloc « Contrats » apparaîtra sous « Dimensionnements ».',
                'attention',
            ],
            $credit === 'attente_deposit' => ['En attente du deposit', ($numero = $client->num_contrat_upya_actuel)
                ? 'Le dossier passera au crédit dès que le deposit du contrat UPYA <b>'.e($numero).'</b> sera complet (full_deposit).'
                : 'Saisissez le n° de contrat UPYA dans le contrat du client : le dossier passera au crédit dès que son deposit sera complet.', 'attention'],
            $credit === 'kyc_a_valider' => ['Validation crédit', 'Dossier transmis au crédit : valider le KYC puis faire le credit scoring.', 'info'],
            $credit === 'scoring_a_faire' => ['Faire le credit scoring', 'KYC validé. Faites le credit scoring dans « Credit scoring », plus bas.', 'info'],
            default => ['Rien en attente', 'Le dossier de ce client est à jour.', 'succes'],
        };

        return new HtmlString('<div class="gr-prochaine-bloc gr-ton-'.$ton.'">'
            .'<span class="gr-surtitre">Prochaine action</span>'
            .'<h4>'.e($titre).'</h4>'
            .'<p>'.$detail.'</p></div>');
    }

    /** Dernier rapport d'installation « système installé » du client (null : pas installé). */
    public static function installation(Client $client): ?\App\Models\Installation
    {
        return \App\Models\Installation::tableDisponible()
            ? $client->installations()->where('systeme_installe', 1)->latest('date_installation')->first()
            : null;
    }

    private static function aFaire(Client $client): ?HtmlString
    {
        $taches = [];

        if (! $client->id_agent) {
            $taches[] = [Heroicon::OutlinedUserPlus, 'Assigner un agent', 'Pour le suivi terrain et les relances', ClientResource::getUrl('edit', ['record' => $client])];
        }

        if ($client->evalSites->isEmpty() && $client->kyc) {
            $taches[] = [Heroicon::OutlinedCalendarDays, 'Planifier le dimensionnement', 'Visite du site et besoins du client', null];
        }

        if (! $taches) {
            return null;
        }

        return new HtmlString('<ul class="gr-taches">'.collect($taches)->map(fn ($t) => '<li>'
            .($t[3] ? '<a href="'.e($t[3]).'">' : '<div>')
            .'<span class="gr-taches-icone">'.self::icone($t[0]).'</span>'
            .'<span><b>'.e($t[1]).'</b><small>'.e($t[2]).'</small></span>'
            .($t[3] ? '</a>' : '</div>')
            .'</li>')->implode('').'</ul>');
    }

    /* ---------------------------------------------------------------------------------------------
     | Historique (onglet) et activité (colonne de droite)
     * ------------------------------------------------------------------------------------------- */

    /** @return Collection<int, array{date: ?Carbon, titre: string, detail: ?string, ton: string}> */
    public static function evenements(Client $client): Collection
    {
        $evenements = collect();

        if ($client->date_client) {
            $evenements->push(['date' => $client->date_client, 'titre' => 'Client enregistré', 'detail' => $client->ref(CanalVente::class, 'id_canal_vente')?->lib_canal_vente, 'ton' => 'succes']);
        }

        if ($client->kyc) {
            $evenements->push(['date' => $client->kyc->date_kyc, 'titre' => 'KYC réalisé', 'detail' => CacheReferences::libelle(Agent::class, $client->kyc->id_agent), 'ton' => 'succes']);
        }

        if (CreditScoring::tableDisponible()) {
            if ($client->kyc?->date_validation_kyc && (int) $client->kyc->statut_validation_kyc !== Kyc::VALIDATION_A_VALIDER) {
                $valide = $client->kyc->estValideParLeCredit();
                $evenements->push([
                    'date' => $client->kyc->date_validation_kyc,
                    'titre' => $valide ? 'KYC validé par le crédit' : 'KYC renvoyé à corriger',
                    'detail' => CacheReferences::libelle(Agent::class, $client->kyc->id_agent_validation_kyc),
                    'ton' => $valide ? 'succes' : 'danger',
                ]);
            }

            foreach ($client->creditScorings()->latest('date_cs')->limit(20)->get() as $scoring) {
                $evenements->push([
                    'date' => $scoring->date_cs,
                    'titre' => 'Credit scoring · '.($scoring->libelleDecision() ?? 'sans décision'),
                    'detail' => CacheReferences::libelle(Agent::class, $scoring->id_agent),
                    'ton' => $scoring->estValide() ? 'succes' : 'attention',
                ]);
            }
        }

        foreach ($client->evalSites as $evaluation) {
            $evenements->push([
                'date' => $evaluation->date_eval_site,
                'titre' => 'Dimensionnement '.($evaluation->estValidee() ? 'validé' : 'à valider'),
                'detail' => CacheReferences::libelle(Produit::class, $evaluation->id_prod),
                'ton' => $evaluation->estValidee() ? 'succes' : 'attention',
            ]);
        }

        if (Contrat::tableDisponible()) {
            foreach ($client->contrats()->get() as $contrat) {
                $evenements->push(['date' => $contrat->date_contrat, 'titre' => 'Contrat '.$contrat->num_contrat, 'detail' => $contrat->statut, 'ton' => 'succes']);
            }
        }

        return $evenements->sortByDesc(fn ($ev) => $ev['date']?->timestamp ?? 0)->values();
    }

    public static function flux(Collection $evenements): HtmlString
    {
        if ($evenements->isEmpty()) {
            return new HtmlString('<p class="gr-vide-valeur">Aucune activité pour le moment.</p>');
        }

        return new HtmlString('<ul class="gr-flux">'.$evenements->map(fn ($ev) => '<li class="gr-ton-'.$ev['ton'].'">'
            .'<span class="gr-flux-point"></span>'
            .'<span><b>'.e($ev['titre']).'</b>'
            .'<small>'.e(collect([$ev['date']?->format('d/m/Y'), $ev['detail']])->filter()->implode(' · ')).'</small></span>'
            .'</li>')->implode('').'</ul>');
    }

    /* ---------------------------------------------------------------------------------------------
     | Outils
     * ------------------------------------------------------------------------------------------- */

    public static function pastille(string $texte, ?string $ton = null, bool $point = false, ?string $classe = null, ?string $titre = null): string
    {
        return '<span class="gr-pastille'.($ton ? ' gr-pastille-'.$ton : '').($classe ? ' '.$classe : '').'"'
            .($titre ? ' title="'.e($titre).'"' : '').'>'
            .($point ? '<span class="gr-pastille-point"></span>' : '')
            .e($texte).'</span>';
    }

    public static function icone(Heroicon $icone, IconSize $taille = IconSize::Small): string
    {
        return generate_icon_html($icone, attributes: new ComponentAttributeBag(['class' => 'gr-icone']), size: $taille)?->toHtml() ?? '';
    }

    /** « COULIBALY ABOUBACAR » → « Coulibaly Aboubacar » (seulement si le texte est tout en capitales). */
    public static function casseTitre(?string $texte): string
    {
        $texte = trim((string) $texte);

        if ($texte === '' || $texte !== mb_strtoupper($texte)) {
            return $texte;
        }

        return mb_convert_case(mb_strtolower($texte), MB_CASE_TITLE);
    }

    public static function chiffres(?string $numero): string
    {
        return preg_replace('/\D/', '', (string) $numero);
    }
}
