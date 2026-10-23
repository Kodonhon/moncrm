<?php

namespace App\Support;

use App\Filament\Resources\PreparationInstallations\PreparationInstallationResource as Preparation;
use App\Models\Client;
use App\Models\CreditScoring;
use App\Models\Installation;
use App\Models\PrepInst;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres du tableau de bord, calculés en quelques requêtes groupées (base distante)
 * et gardés 5 minutes en cache, par utilisateur (chacun ne voit que son périmètre).
 */
class TableauDeBordDonnees
{
    public static function calculer(): array
    {
        return Cache::remember('tableau-de-bord:'.(Auth::id() ?? 'console'), now()->addMinutes(5), fn () => self::calculerSansCache());
    }

    public static function calculerSansCache(): array
    {
        $actifs = fn () => Client::query()->where(fn ($q) => $q->whereNull('visibilite_client')->orWhere('visibilite_client', '<>', 0));
        $installationOk = Installation::tableDisponible();
        $creditOk = CreditScoring::tableDisponible();

        // Étapes du parcours, en une requête
        $etapes = (array) $actifs()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when id_client in (select id_client from kyc where visibilite_kyc = 1) then 1 else 0 end) as kyc')
            ->selectRaw('sum(case when id_client in (select id_client from eval_site) then 1 else 0 end) as dimensionnes')
            ->when($creditOk, fn ($q) => $q->selectRaw('sum(case when id_client in (select id_client from credit_scoring where '.CreditScoring::CHAMP_DECISION." = '".CreditScoring::DECISION_VALIDEE."') then 1 else 0 end) as credit"))
            ->when($installationOk, fn ($q) => $q
                ->selectRaw('sum(case when id_client in (select id_client from installation where systeme_installe = 1) then 1 else 0 end) as installes')
                // Clients avec KYC : installés, et encore en cours (KYC fait, pas installés)
                ->selectRaw('sum(case when id_client in (select id_client from kyc where visibilite_kyc = 1)
                    and id_client in (select id_client from installation where systeme_installe = 1) then 1 else 0 end) as kyc_installes'))
            ->selectRaw('sum(case when date_client >= ? then 1 else 0 end) as semaine', [now()->subDays(7)->toDateString()])
            ->selectRaw('sum(case when date_client >= ? and date_client < ? then 1 else 0 end) as semaine_precedente', [now()->subDays(14)->toDateString(), now()->subDays(7)->toDateString()])
            ->selectRaw('sum(case when date_client >= ? then 1 else 0 end) as mois', [now()->startOfMonth()->toDateString()])
            ->toBase()
            ->first();
        $n = fn ($cle) => (int) ($etapes[$cle] ?? 0);

        // Nouveaux clients par mois (12 mois) et par année (5 ans)
        $debut = now()->subMonths(11)->startOfMonth();
        $parJour = $actifs()->where('date_client', '>=', now()->subYears(4)->startOfYear()->toDateString())
            ->selectRaw('date_client, count(*) as total')->groupBy('date_client')->toBase()->get();
        $parMois = $parJour->groupBy(fn ($l) => substr((string) $l->date_client, 0, 7))->map->sum('total');
        $parAn = $parJour->groupBy(fn ($l) => substr((string) $l->date_client, 0, 4))->map->sum('total');
        $mois = collect(range(0, 11))->map(fn ($i) => $debut->copy()->addMonths($i))
            ->map(fn (Carbon $m) => ['libelle' => ucfirst($m->translatedFormat('M')), 'detail' => $m->translatedFormat('F Y'), 'valeur' => (int) ($parMois[$m->format('Y-m')] ?? 0)])->all();
        $annees = collect(range(4, 0))->map(fn ($i) => now()->subYears($i)->year)
            ->map(fn ($a) => ['libelle' => (string) $a, 'detail' => (string) $a, 'valeur' => (int) ($parAn[(string) $a] ?? 0)])->all();

        // Clients par source (saisie sur le client, sinon celle du canal de vente)
        $sources = $actifs()
            ->leftJoin('canal_vente', 'canal_vente.id_canal_vente', '=', 'client.id_canal_vente')
            ->leftJoin('src_client', 'src_client.id_src_client', '=', DB::raw('coalesce(client.id_src_client, canal_vente.id_src_client)'))
            ->selectRaw("coalesce(src_client.lib_src_client, 'Non renseignée') as source, count(*) as total")
            ->groupBy('source')->orderByDesc('total')->toBase()->get()
            ->map(fn ($l) => ['libelle' => $l->source, 'valeur' => (int) $l->total])->all();

        // Installations : total et ce mois-ci
        $installations = $installationOk ? (array) DB::table('installation')->selectRaw('
                sum(case when systeme_installe = 1 then 1 else 0 end) as installes,
                sum(case when systeme_installe = 1 and date_installation >= ? then 1 else 0 end) as mois,
                sum(case when systeme_installe = 0 then 1 else 0 end) as echecs', [now()->startOfMonth()->toDateString()])->first() : [];

        // Activité récente : derniers clients enregistrés et leur étape
        $recents = $actifs()->with(['kyc'])->withCount('evalSites')
            ->orderByDesc('date_client')->orderByDesc('id_client')->limit(6)->get()
            ->map(fn (Client $c) => [
                'id' => $c->id_client,
                'nom' => $c->nom_complet,
                'avatar' => \App\Filament\Resources\Clients\Schemas\ClientInfolist::avatar($c, 'gr-ent-avatar gr-liste-avatar'),
                'date' => $c->date_client?->format('d M Y'),
                'lieu' => $c->ville_et_shop,
                'etape' => match (true) {
                    $installationOk && $c->estInstalle() => ['Installé', 'vert'],
                    (int) $c->eval_sites_count > 0 => ['Dimensionné', 'violet'],
                    (bool) $c->kyc => ['KYC fait', 'orange'],
                    default => ['Nouveau', 'gris'],
                },
            ])->all();

        // Relances : appels du CX à refaire
        $relances = [];
        if (PrepInst::tableDisponible() && $creditOk) {
            $relances = Preparation::filtrerEtat(Preparation::getEloquentQuery(), 'a_rappeler')
                ->orderBy('date_cs')->limit(4)->get()
                ->map(fn ($s) => ['id' => $s->id_client, 'nom' => $s->client?->nom_complet, 'detail' => PrepInst::RESULTATS[(int) $s->dernier_resultat] ?? 'À rappeler'])
                ->all();
        }

        $total = $n('total');
        $semaine = $n('semaine');
        $precedente = $n('semaine_precedente');

        return [
            'total' => $total,
            'semaine' => $semaine,
            'evolution_semaine' => $precedente ? round(($semaine - $precedente) / $precedente * 100, 1) : null,
            'mois' => $n('mois'),
            'etapes' => array_values(array_filter([
                ['Prospects', $total, 'gris'],
                ['KYC faits', $n('kyc'), 'orange'],
                ['Dimensionnés', $n('dimensionnes'), 'violet'],
                $creditOk ? ['Validés crédit', $n('credit'), 'bleu'] : null,
                $installationOk ? ['Installés', $n('installes'), 'vert'] : null,
            ])),
            // Parmi les clients qui ont fait leur KYC : part installée, et dossiers encore en cours
            'taux_installation' => $n('kyc') ? round($n('kyc_installes') / $n('kyc') * 100, 1) : 0,
            'en_cours' => $n('kyc') - $n('kyc_installes'),
            'installes' => (int) ($installations['installes'] ?? 0),
            'installes_mois' => (int) ($installations['mois'] ?? 0),
            'echecs' => (int) ($installations['echecs'] ?? 0),
            'mois_series' => $mois,
            'annees_series' => $annees,
            'sources' => $sources,
            'recents' => $recents,
            'relances' => $relances,
            'calcule_a' => now()->format('H:i'),
        ];
    }
}
