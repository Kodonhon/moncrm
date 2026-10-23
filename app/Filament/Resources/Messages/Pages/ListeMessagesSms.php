<?php

namespace App\Filament\Resources\Messages\Pages;

use App\Filament\Messages\EnvoiSms;
use App\Filament\Resources\Messages\SmsMessageResource;
use App\Models\SmsMessage;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListeMessagesSms extends ListRecords
{
    protected static string $resource = SmsMessageResource::class;

    public function getTitle(): string
    {
        return 'Historique des envois';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        $mois = SmsMessage::query()->where('statut', 'envoye')->where('created_at', '>=', now()->startOfMonth())->sum('nb_sms');

        return 'Chaque SMS envoyé par le CRM, avec la réponse de LeTexto. Ce mois-ci : '.number_format((int) $mois, 0, ',', ' ').' SMS facturés.';
    }

    protected function getHeaderActions(): array
    {
        return [EnvoiSms::action()];
    }

    public function getTabs(): array
    {
        $compte = fn (?string $statut) => fn () => number_format(SmsMessage::query()->when($statut, fn ($q) => $q->where('statut', $statut))->count(), 0, ',', ' ');
        $tab = fn (string $libelle, string $statut, string $couleur) => Tab::make($libelle)->badge($compte($statut))->badgeColor($couleur)
            ->modifyQueryUsing(fn (Builder $q) => $q->where('statut', $statut));

        return [
            'tous' => Tab::make('Tous')->badge($compte(null)),
            'envoye' => $tab('Envoyés', 'envoye', 'success'),
            'echec' => $tab('Échecs', 'echec', 'danger'),
            'simule' => $tab('Simulés (test)', 'simule', 'info'),
        ];
    }
}
