<?php

namespace App\Support;

use Filament\Support\Icons\Heroicon;

/**
 * Table unique des statuts du CRM : libellé, ton (couleur de la charte) et icône.
 * Utilisée par le composant <x-crm.pastille-statut> (et à réutiliser dans les tableaux, fiches et cartes).
 * Un statut n'est jamais signalé par la couleur seule : toujours libellé + icône.
 *
 * Tons : vert (fait / permis), violet (information / en cours), orange (à faire / attention),
 *        rouge (retard / refus / interdit), gris (neutre / à venir).
 */
class Statuts
{
    /** @var array<string, array{0: string, 1: string, 2: Heroicon}> clé => [libellé, ton, icône] */
    private const STATUTS = [
        // Client
        'client.actif' => ['Actif', 'vert', Heroicon::OutlinedCheckCircle],
        'client.desactive' => ['Désactivé', 'rouge', Heroicon::OutlinedNoSymbol],
        // KYC (kyc.statut_validation_kyc)
        'kyc.a_faire' => ['KYC à faire', 'orange', Heroicon::OutlinedExclamationCircle],
        'kyc.0' => ['KYC à valider', 'violet', Heroicon::OutlinedClock],
        'kyc.1' => ['KYC validé', 'vert', Heroicon::OutlinedCheckBadge],
        'kyc.2' => ['KYC à corriger', 'orange', Heroicon::OutlinedArrowUturnLeft],
        'kyc.expire' => ['Pièce expirée', 'rouge', Heroicon::OutlinedExclamationTriangle],
        // Évaluation du site (eval_site.statut_eval_site)
        'eval.0' => ['À valider', 'orange', Heroicon::OutlinedClock],
        'eval.1' => ['Validée', 'vert', Heroicon::OutlinedCheckCircle],
        // Contrat
        'contrat.actif' => ['Actif', 'vert', Heroicon::OutlinedCheckCircle],
        'contrat.resilie' => ['Résilié', 'rouge', Heroicon::OutlinedXCircle],
        'contrat.termine' => ['Terminé', 'gris', Heroicon::OutlinedFlag],
        // Paiement AMT
        'paiement.full_deposit' => ['Dépôt payé', 'vert', Heroicon::OutlinedBanknotes],
        'paiement.en_attente' => ['Dépôt en attente', 'orange', Heroicon::OutlinedClock],
        // Règles (style de l'affiche)
        'regle.permis' => ['Permis', 'vert', Heroicon::OutlinedCheckCircle],
        'regle.autorisation' => ['Soumis à autorisation', 'violet', Heroicon::OutlinedUserCircle],
        'regle.interdit' => ['Interdit', 'rouge', Heroicon::OutlinedNoSymbol],
        'regle.registre' => ['Autorisation + registre', 'orange', Heroicon::OutlinedClipboardDocumentCheck],
    ];

    /** @return array{libelle: string, ton: string, icone: Heroicon} */
    public static function info(string $cle, ?string $libelleParDefaut = null): array
    {
        [$libelle, $ton, $icone] = self::STATUTS[$cle] ?? [$libelleParDefaut ?? $cle, 'gris', Heroicon::OutlinedEllipsisHorizontalCircle];

        return ['libelle' => $libelle, 'ton' => $ton, 'icone' => $icone];
    }

    /** Ton Filament correspondant (badges des tableaux) : vert → success, violet → info… */
    public static function couleurFilament(string $cle): string
    {
        return match (self::info($cle)['ton']) {
            'vert' => 'success',
            'violet' => 'info',
            'orange' => 'warning',
            'rouge' => 'danger',
            default => 'gray',
        };
    }
}
