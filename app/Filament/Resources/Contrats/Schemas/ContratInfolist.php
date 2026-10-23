<?php

namespace App\Filament\Resources\Contrats\Schemas;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Schemas\ClientInfolist as Fiche;
use App\Models\Agent;
use App\Models\Contrat;
use App\Models\CreditScoring;
use App\Models\PaiementContrat;
use App\Support\CacheReferences;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Page d'un contrat, sur le modèle du « compte » SunCulture :
 *   1. bandeau « Prochaine action » du client ;
 *   2. « Résumé du contrat » (pastille du deposit, menu « Actions ») ; le nom du titulaire ouvre la fiche client ;
 *   3. « Parcours client » : frise verticale, avec le deposit de ce contrat ;
 *   4. onglets : paiement, credit scoring, historique.
 */
class ContratInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 12])
            ->components([
                Section::make()
                    ->key('prochaine-action-contrat')
                    ->extraAttributes(['class' => 'gr-banniere'])
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('prochaine_action')
                            ->hiddenLabel()
                            ->state(fn (Contrat $record) => $record->client ? Fiche::prochaineAction($record->client) : null),
                    ]),
                Section::make(fn (Contrat $record) => new HtmlString('<span class="gr-compte-titre">Résumé du contrat'.self::pastilleDeposit($record).'</span>'))
                    ->key('resume-contrat')
                    ->extraAttributes(['class' => 'gr-compte'])
                    ->columnSpan(['default' => 1, 'lg' => 8])
                    ->afterHeader([
                        ActionGroup::make([
                            Action::make('ficheClient')
                                ->label('Ouvrir la fiche client')
                                ->icon(Heroicon::OutlinedUser)
                                ->url(fn (Contrat $record) => $record->client ? ClientResource::getUrl('view', ['record' => $record->client]) : null),
                            Action::make('appeler')
                                ->label('Appeler le client')
                                ->icon(Heroicon::OutlinedPhone)
                                ->url(fn (Contrat $record) => 'tel:+'.Fiche::chiffres($record->client?->contact1_client))
                                ->visible(fn (Contrat $record) => filled($record->client?->contact1_client)),
                            Action::make('whatsapp')
                                ->label('WhatsApp')
                                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                                ->url(fn (Contrat $record) => 'https://wa.me/'.Fiche::chiffres($record->client?->contact1_client), shouldOpenInNewTab: true)
                                ->visible(fn (Contrat $record) => filled($record->client?->contact1_client)),
                            Action::make('voirDeposit')
                                ->label('Voir dans Deposit')
                                ->icon(Heroicon::OutlinedBanknotes)
                                ->url(fn (Contrat $record) => \App\Filament\Resources\Deposits\DepositResource::getUrl(parameters: ['search' => $record->num_contrat_upya]))
                                ->visible(fn (Contrat $record) => Contrat::upyaDisponible() && filled($record->num_contrat_upya)),
                        ])
                            ->label('Actions')
                            ->icon(Heroicon::ChevronDown)
                            ->iconPosition('after')
                            ->button()
                            ->color('primary'),
                    ])
                    ->schema([
                        TextEntry::make('resume')
                            ->hiddenLabel()
                            ->state(fn (Contrat $record) => $record->client ? Fiche::champsCompte($record->client, $record) : null),
                    ]),
                Section::make('Parcours client')
                    ->key('parcours-contrat')
                    ->extraAttributes(['class' => 'gr-parcours-section'])
                    ->columnSpan(['default' => 1, 'lg' => 4])
                    ->schema([
                        TextEntry::make('parcours')
                            ->hiddenLabel()
                            ->state(fn (Contrat $record) => $record->client ? Fiche::frise($record->client, $record) : null),
                    ]),
                Tabs::make('Détails du contrat')
                    ->key('onglets-contrat', isInheritable: false)
                    ->extraAttributes(['class' => 'gr-onglets-fiche'])
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Paiement')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->schema([TextEntry::make('paiement')->hiddenLabel()->state(fn (Contrat $record) => self::paiement($record))]),
                        Tab::make('Credit scoring')
                            ->icon(Heroicon::OutlinedScale)
                            ->visible(fn () => CreditScoring::tableDisponible())
                            ->schema([TextEntry::make('scorings')->hiddenLabel()->state(fn (Contrat $record) => self::scorings($record))]),
                        Tab::make('Historique')
                            ->icon(Heroicon::OutlinedClock)
                            ->schema([TextEntry::make('historique')->hiddenLabel()
                                ->state(fn (Contrat $record) => $record->client ? Fiche::flux(Fiche::evenements($record->client)) : null)]),
                    ]),
            ]);
    }

    private static function pastilleDeposit(Contrat $contrat): string
    {
        $deposit = Contrat::upyaDisponible() && filled($contrat->num_contrat_upya) && PaiementContrat::verificationActive()
            ? PaiementContrat::depositDe($contrat->num_contrat_upya) : null;

        return match ($deposit['statut'] ?? null) {
            'full_deposit' => Fiche::pastille('Dépôt complet', 'succes', point: true),
            'partial_deposit' => Fiche::pastille('Dépôt partiel', 'attention', point: true),
            default => Fiche::pastille(filled($contrat->num_contrat_upya ?? null) ? 'Aucun deposit' : 'N° UPYA à saisir', null, point: true),
        };
    }

    /** Ligne complète de la base des ventes pour ce contrat (dates de premier et dernier paiement comprises). */
    private static function paiement(Contrat $contrat): HtmlString
    {
        if (! Contrat::upyaDisponible() || blank($contrat->num_contrat_upya)) {
            return new HtmlString('<p class="gr-vide-valeur">Pas de n° de contrat UPYA sur ce contrat : le paiement ne peut pas être retrouvé.</p>');
        }
        if (! PaiementContrat::verificationActive()) {
            return new HtmlString('<p class="gr-vide-valeur">Base des ventes non joignable pour le moment.</p>');
        }

        $numero = PaiementContrat::normaliser($contrat->num_contrat_upya);
        $ligne = PaiementContrat::query()->whereIn('num_contrat_upya', [$numero, '0'.$numero, $contrat->num_contrat_upya])->first();
        if (! $ligne) {
            return new HtmlString('<p class="gr-vide-valeur">Aucun paiement pour le contrat UPYA '.e($contrat->num_contrat_upya).' pour l\'instant.</p>');
        }

        $montant = fn ($v) => $v === null ? null : e(number_format((float) $v, 0, ',', ' ')).' F CFA';

        return new HtmlString('<dl class="gr-compte-grille">'.collect([
            ['Statut', Fiche::pastille(PaiementContrat::STATUTS[$ligne->statut_amt] ?? $ligne->statut_amt, $ligne->statut_amt === 'full_deposit' ? 'succes' : 'attention')],
            ['Entité', e(PaiementContrat::ENTITES[$ligne->entite] ?? $ligne->entite)],
            ['Dépôt', $montant($ligne->mt_deposit)],
            ['Total payé', $montant($ligne->total_paye)],
            ['Date du dépôt', e($ligne->date_deposit?->format('d/m/Y'))],
            ['Premier paiement', e($ligne->premier_paiement?->format('d/m/Y H:i'))],
            ['Dernier paiement', e($ligne->dernier_paiement?->format('d/m/Y H:i'))],
            ['Mis à jour', e($ligne->date_maj?->format('d/m/Y H:i'))],
        ])->map(fn ($c) => '<div><dt>'.e($c[0]).'</dt><dd>'.(filled($c[1]) ? $c[1] : '<span class="gr-vide-valeur">—</span>').'</dd></div>')->implode('').'</dl>');
    }

    /** Scorings du client, du plus récent au plus ancien. */
    private static function scorings(Contrat $contrat): HtmlString
    {
        $scorings = $contrat->client?->creditScorings()->latest('date_cs')->latest('heure_cs')->get() ?? collect();

        if ($scorings->isEmpty()) {
            return new HtmlString('<p class="gr-vide-valeur">Aucun credit scoring pour ce client.</p>');
        }

        return new HtmlString('<table class="gr-table-simple"><thead><tr><th>Date</th><th>Décision</th><th>Appel</th><th>Agent crédit</th><th>Commentaire</th></tr></thead><tbody>'
            .$scorings->map(fn (CreditScoring $s) => '<tr>'
                .'<td>'.e($s->date_cs?->format('d/m/Y')).'</td>'
                .'<td>'.Fiche::pastille($s->libelleDecision() ?? '—', $s->estValide() ? 'succes' : 'attention').'</td>'
                .'<td>'.e($s->libelleReponse(CreditScoring::CHAMP_APPEL)).'</td>'
                .'<td>'.e(CacheReferences::libelle(Agent::class, $s->id_agent)).'</td>'
                .'<td>'.e(str($s->{CreditScoring::CHAMP_COMMENTAIRE})->limit(90)).'</td>'
                .'</tr>')->implode('')
            .'</tbody></table>');
    }
}
