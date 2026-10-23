<?php

namespace App\Support;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\CreditScoring;
use App\Models\EvalSite;
use App\Models\Kyc;
use App\Models\PrepInst;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications du parcours client, envoyées dans la cloche des personnes concernées :
 * - dossier transmis au crédit (1er dimensionnement d'un client qui a un KYC) → agents qui valident les KYC ;
 * - KYC renvoyé « à corriger » → agent terrain du KYC ;
 * - KYC validé → agents qui font le credit scoring ;
 * - credit scoring validé → agents CX (préparation installation) ;
 * - client « prêt pour l'installation » → agent terrain du client.
 * Chaque destinataire doit avoir la permission et voir le client dans son périmètre ; l'auteur n'est pas notifié.
 * Une notification qui échoue n'empêche jamais l'enregistrement.
 */
class NotificationsCrm
{
    public static function ecouter(): void
    {
        EvalSite::created(fn (EvalSite $e) => self::envoyer(function () use ($e) {
            $client = $e->client;
            if ($client?->kyc && $client->evalSites()->count() === 1) {
                self::notifier('kyc.valider', $client, 'Nouveau dossier au crédit',
                    $client->nom_complet.' : KYC et dimensionnement faits, KYC à valider.', Heroicon::OutlinedInboxArrowDown, 'warning');
            }
        }));

        Kyc::updated(fn (Kyc $k) => self::envoyer(function () use ($k) {
            if (! $k->wasChanged('statut_validation_kyc') || ! ($client = $k->client)) {
                return;
            }

            match ((int) $k->statut_validation_kyc) {
                Kyc::VALIDATION_A_CORRIGER => self::notifierAgent($k->id_agent ?? $client->id_agent, $client, 'KYC à corriger',
                    $client->nom_complet.' : '.str((string) $k->motif_validation_kyc)->limit(120), Heroicon::OutlinedArrowUturnLeft, 'danger'),
                Kyc::VALIDATION_VALIDE => self::notifier('credit_scoring.creer', $client, 'KYC validé : scoring à faire',
                    $client->nom_complet.' est prêt pour le credit scoring.', Heroicon::OutlinedScale, 'info'),
                default => null,
            };
        }));

        $scoringValide = fn (CreditScoring $s) => self::envoyer(function () use ($s) {
            if ($s->estValide() && ($s->wasRecentlyCreated || $s->wasChanged(CreditScoring::CHAMP_DECISION)) && ($client = $s->client)) {
                self::notifier('preparation_installation.creer', $client, 'Nouveau client à préparer',
                    $client->nom_complet.' a été validé au credit scoring : appel CX à faire.', Heroicon::OutlinedWrenchScrewdriver, 'success');
            }
        });
        CreditScoring::created($scoringValide);
        CreditScoring::updated($scoringValide);

        PrepInst::created(fn (PrepInst $p) => self::envoyer(function () use ($p) {
            if ((int) $p->resul_prep_inst === PrepInst::PRET && ($client = $p->client)) {
                self::notifierAgent($client->id_agent, $client, 'Client prêt pour l\'installation',
                    $client->nom_complet.' est prêt pour l\'installation (appel CX du '.now()->format('d/m/Y').').', Heroicon::OutlinedCheckBadge, 'success');
            }
        }));
    }

    private static function disponible(): bool
    {
        return Cache::remember('notifications-crm-disponible', now()->addMinutes(10),
            fn () => Schema::hasTable('notifications') && Acces::installe());
    }

    private static function envoyer(\Closure $traitement): void
    {
        if (! self::disponible()) {
            return;
        }

        try {
            $traitement();
        } catch (\Throwable $e) {
            Log::warning('Notification CRM non envoyée : '.$e->getMessage());
        }
    }

    /** Utilisateurs actifs ayant la permission et voyant ce client (hors auteur). */
    private static function destinataires(string $permission, Client $client): Collection
    {
        return User::query()->where('actif', true)->whereKeyNot(auth()->id() ?? 0)->get()
            ->filter(fn (User $u) => Acces::peut($permission, $u) && Acces::peutVoirClient($u, $client))
            ->values();
    }

    private static function notifier(string $permission, Client $client, string $titre, string $texte, Heroicon $icone, string $couleur): void
    {
        self::expedier(self::destinataires($permission, $client), $client, $titre, $texte, $icone, $couleur);
    }

    private static function notifierAgent(?int $idAgent, Client $client, string $titre, string $texte, Heroicon $icone, string $couleur): void
    {
        if (! $idAgent) {
            return;
        }

        self::expedier(User::query()->where('actif', true)->where('id_agent', $idAgent)->whereKeyNot(auth()->id() ?? 0)->get(),
            $client, $titre, $texte, $icone, $couleur);
    }

    private static function expedier(Collection $utilisateurs, Client $client, string $titre, string $texte, Heroicon $icone, string $couleur): void
    {
        if ($utilisateurs->isEmpty()) {
            return;
        }

        Notification::make()
            ->title($titre)
            ->body($texte)
            ->icon($icone)
            ->iconColor($couleur)
            ->actions([
                Action::make('ouvrir')
                    ->label('Ouvrir la fiche')
                    ->url(ClientResource::getUrl('view', ['record' => $client->id_client]))
                    ->markAsRead(),
            ])
            ->sendToDatabase($utilisateurs);
    }
}
