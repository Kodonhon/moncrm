<?php

namespace App\Support;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Contrats\ContratResource;
use App\Models\Contrat;
use App\Models\PaiementContrat;

/**
 * Liens des tableaux : un clic sur le nom d'un client ouvre sa fiche,
 * un clic sur un n° de contrat (CRM ou UPYA) ouvre la page du contrat.
 */
class LiensCrm
{
    /** Contrats du CRM par n° UPYA normalisé, chargés une fois par requête : [numéro => id_contrat] */
    private static ?array $contratsUpya = null;

    public static function client(?string $idClient): ?string
    {
        return filled($idClient) ? ClientResource::getUrl('view', ['record' => $idClient]) : null;
    }

    /** Page du contrat du CRM qui porte ce n° UPYA (null : aucun contrat CRM avec ce numéro). */
    public static function contratUpya(?string $numero): ?string
    {
        if (blank($numero) || ! Contrat::upyaDisponible()) {
            return null;
        }

        self::$contratsUpya ??= Contrat::query()->whereNotNull('num_contrat_upya')->pluck('id_contrat', 'num_contrat_upya')
            ->mapWithKeys(fn ($id, $upya) => [PaiementContrat::normaliser((string) $upya) => $id])->all();

        $id = self::$contratsUpya[PaiementContrat::normaliser($numero)] ?? null;

        return $id ? ContratResource::getUrl('view', ['record' => $id]) : null;
    }

    /** Page d'un contrat du CRM d'après son n° (CT-2026-000001). */
    public static function contrat(?string $numContrat): ?string
    {
        if (blank($numContrat) || ! Contrat::tableDisponible()) {
            return null;
        }

        $id = Contrat::query()->where('num_contrat', $numContrat)->value('id_contrat');

        return $id ? ContratResource::getUrl('view', ['record' => $id]) : null;
    }
}
