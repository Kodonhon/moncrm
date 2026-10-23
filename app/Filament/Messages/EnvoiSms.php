<?php

namespace App\Filament\Messages;

use App\Filament\Resources\Messages\SmsMessageResource;
use App\Models\Client;
use App\Models\SmsConfiguration;
use App\Models\SmsModele;
use App\Sms\LeTexto;
use App\Support\Acces;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Fenêtre « Envoyer un SMS », utilisée depuis Messages (destinataires au choix), depuis la liste des clients
 * (clients cochés) et depuis un modèle (modèle déjà choisi).
 * Seuls les modèles approuvés et actifs sont proposés ; le texte libre est réservé aux approbateurs (messages.valider).
 */
class EnvoiSms
{
    public static function peutEnvoyer(): bool
    {
        return SmsConfiguration::installe() && Acces::peut('messages.envoyer');
    }

    public static function texteLibre(): bool
    {
        return Acces::peut('messages.valider');
    }

    /** Bouton « Envoyer un SMS » (destinataires à choisir). */
    public static function action(string $nom = 'envoyerSms', ?SmsModele $modele = null): Action
    {
        return Action::make($nom)
            ->label('Envoyer un SMS')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn () => self::peutEnvoyer())
            ->modalHeading('Envoyer un SMS')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Envoyer')
            ->fillForm(fn ($record = null) => ($m = $modele ?? ($record instanceof SmsModele ? $record : null))
                ? ['sms_modele_id' => $m->getKey(), 'contenu' => $m->contenu, 'mode' => 'clients']
                : ['mode' => 'clients'])
            ->schema(fn () => self::champs(true))
            ->action(fn (array $data) => self::traiter($data));
    }

    /** Action groupée sur la liste des clients. */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('envoyerSms')
            ->label('Envoyer un SMS')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn () => self::peutEnvoyer())
            ->modalHeading(fn (Collection $records) => 'Envoyer un SMS à '.$records->count().' client(s)')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Envoyer')
            ->schema(fn () => self::champs(false))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => self::traiter($data, $records));
    }

    public static function champs(bool $avecDestinataires): array
    {
        $config = SmsConfiguration::actuelle();

        return array_values(array_filter([
            TextEntry::make('avertissement')
                ->hiddenLabel()
                ->visible(fn () => $config->mode_test || ! $config->prete())
                ->state(new HtmlString('<span class="gr-sms-test">'
                    .($config->mode_test ? 'Mode test actif : les SMS seront seulement inscrits dans l\'historique, rien n\'est envoyé.'
                        : 'L\'API LeTexto n\'est pas encore configurée (Messages > Configuration) : rien ne sera envoyé.')
                    .'</span>')),
            Select::make('sms_modele_id')
                ->label('Modèle')
                ->options(fn () => SmsModele::query()->utilisables()->orderBy('nom')->pluck('nom', 'id')->all())
                ->searchable()
                ->required(fn () => ! self::texteLibre())
                ->placeholder(self::texteLibre() ? 'Aucun (message libre)' : 'Choisir un modèle approuvé')
                ->helperText('Seuls les modèles approuvés et actifs sont proposés.')
                ->live()
                ->afterStateUpdated(fn ($state, Set $set) => $set('contenu', $state ? SmsModele::find($state)?->contenu : '')),
            Textarea::make('contenu')
                ->label('Message')
                ->rows(5)
                ->required()
                ->readOnly(fn (Get $get) => ! self::texteLibre() || filled($get('sms_modele_id')))
                ->live(debounce: 400)
                ->helperText(fn (?string $state) => new HtmlString(
                    '<b>'.e(LeTexto::compteur(LeTexto::rendre((string) $state))).'</b> · Variables : '
                    .collect(LeTexto::VARIABLES)->map(fn ($aide, $v) => '<code title="'.e($aide).'">{'.$v.'}</code>')->implode(' '))),
            $avecDestinataires ? Radio::make('mode')
                ->label('Destinataires')
                ->options(['clients' => 'Clients du CRM', 'numeros' => 'Numéros saisis'])
                ->inline()
                ->default('clients')
                ->live() : null,
            $avecDestinataires ? Select::make('clients')
                ->label('Clients')
                ->multiple()
                ->searchable()
                ->required(fn (Get $get) => $get('mode') === 'clients')
                ->visible(fn (Get $get) => $get('mode') === 'clients')
                ->getSearchResultsUsing(fn (string $search) => self::rechercherClients($search))
                ->getOptionLabelsUsing(fn (array $values) => Client::query()->whereIn('id_client', $values)->get()
                    ->mapWithKeys(fn (Client $c) => [$c->id_client => $c->nom_complet])->all())
                ->live() : null,
            $avecDestinataires ? Textarea::make('numeros')
                ->label('Numéros')
                ->rows(4)
                ->placeholder("0701020304\n0505060708")
                ->helperText('Un numéro par ligne (ou séparés par des virgules). Les variables du client restent vides.')
                ->required(fn (Get $get) => $get('mode') === 'numeros')
                ->visible(fn (Get $get) => $get('mode') === 'numeros') : null,
            TextEntry::make('apercu')
                ->label('Aperçu')
                ->state(function (Get $get) {
                    $client = ($id = collect($get('clients') ?? [])->first()) ? Client::find($id) : null;
                    $texte = LeTexto::rendre((string) $get('contenu'), $client);

                    return new HtmlString('<div class="gr-sms-bulle">'.nl2br(e($texte ?: '…')).'</div>'
                        .($client ? '<small class="gr-sms-apercu-pour">Pour '.e($client->nom_complet).'</small>' : ''));
                }),
        ]));
    }

    /** @return array<string, string> */
    public static function rechercherClients(string $search): array
    {
        $chiffres = preg_replace('/\D/', '', $search);

        return Client::query()
            ->whereNotNull('contact1_client')
            ->where(fn (Builder $q) => $q
                ->where('nom_client', 'like', '%'.mb_strtoupper($search).'%')
                ->orWhere('prenoms_client', 'like', "%{$search}%")
                ->when($chiffres !== '', fn (Builder $q) => $q->orWhere('contact1_client', 'like', "%{$chiffres}%")))
            ->limit(30)
            ->get(['id_client', 'nom_client', 'prenoms_client', 'contact1_client'])
            ->mapWithKeys(fn (Client $c) => [$c->id_client => $c->nom_complet.' — '.$c->contact1_client])
            ->all();
    }

    public static function traiter(array $data, ?Collection $clients = null): void
    {
        $config = SmsConfiguration::actuelle();
        $modele = filled($data['sms_modele_id'] ?? null) ? SmsModele::find($data['sms_modele_id']) : null;

        if ($modele && ! $modele->utilisable()) {
            Notification::make()->title('Ce modèle n\'est plus approuvé ou actif')->danger()->send();

            return;
        }
        if (! $modele && ! self::texteLibre()) {
            Notification::make()->title('Choisissez un modèle approuvé')->danger()->send();

            return;
        }

        $contenu = $modele ? $modele->contenu : (string) ($data['contenu'] ?? '');

        if ($clients === null) {
            $destinataires = ($data['mode'] ?? 'clients') === 'numeros'
                ? collect(preg_split('/[\s,;]+/', (string) ($data['numeros'] ?? '')))->filter()->unique()->values()
                : Client::query()->whereIn('id_client', $data['clients'] ?? [])->get();
        } else {
            $destinataires = $clients;
        }

        if ($destinataires->isEmpty()) {
            Notification::make()->title('Aucun destinataire')->warning()->send();

            return;
        }

        $limite = max(1, (int) $config->limite_par_envoi);
        if ($destinataires->count() > $limite) {
            Notification::make()->title('Trop de destinataires')
                ->body("Maximum {$limite} SMS par envoi (réglable dans Messages > Configuration).")->danger()->send();

            return;
        }

        @set_time_limit(0);
        $lot = (string) Str::uuid();
        $resultats = $destinataires->map(fn ($d) => LeTexto::envoyer($d, $contenu, $modele, $lot)->statut)->countBy();

        $corps = collect([
            ($resultats['envoye'] ?? 0) ? ($resultats['envoye'].' envoyé(s)') : null,
            ($resultats['simule'] ?? 0) ? ($resultats['simule'].' simulé(s)') : null,
            ($resultats['echec'] ?? 0) ? ($resultats['echec'].' en échec') : null,
        ])->filter()->implode(' · ');

        Notification::make()
            ->title(($resultats['echec'] ?? 0) ? 'Envoi terminé avec des erreurs' : 'Envoi terminé')
            ->body($corps)
            ->{($resultats['echec'] ?? 0) ? 'warning' : 'success'}()
            ->actions([
                Action::make('historique')->label('Voir l\'historique')->link()
                    ->url(SmsMessageResource::getUrl('index', ['filters' => ['lot' => ['value' => $lot]]])),
            ])
            ->send();
    }
}
