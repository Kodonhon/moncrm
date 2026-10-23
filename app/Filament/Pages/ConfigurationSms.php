<?php

namespace App\Filament\Pages;

use App\Models\SmsConfiguration;
use App\Sms\LeTexto;
use App\Support\Acces;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Messages > Configuration : compte LeTexto (token API, nom d'expéditeur), mode test, numéro de test
 * et, en avancé, l'adresse et le nom des paramètres de l'API.
 * Le token est chiffré en base et n'est jamais réaffiché : laisser le champ vide garde le token enregistré.
 */
class ConfigurationSms extends Page
{
    protected static ?string $slug = 'messages/configuration';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $title = 'Configuration des SMS';

    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return SmsConfiguration::installe() && Acces::peut('messages.configurer');
    }

    public function mount(): void
    {
        $config = SmsConfiguration::actuelle();
        $this->form->fill([...collect($config->attributesToArray())->except(['token', 'id', 'created_at', 'updated_at'])->all(), 'token' => null]);
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'Connexion à l\'API LeTexto utilisée pour envoyer les SMS.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tester')
                ->label('Envoyer un SMS de test')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->outlined()
                ->action(fn () => $this->tester()),
            Action::make('enregistrer')
                ->label('Enregistrer')
                ->icon(Heroicon::OutlinedCheck)
                ->action(fn () => $this->enregistrer()),
        ];
    }

    public function enregistrer(): void
    {
        $donnees = $this->form->getState();
        $config = SmsConfiguration::actuelle();

        if (blank($donnees['token'] ?? null)) {
            unset($donnees['token']); // champ vide : on garde le token enregistré
        }

        if (($donnees['actif'] ?? false) && blank($donnees['token'] ?? $config->token)) {
            Notification::make()->title('Token API manquant')->body('Collez le token LeTexto avant d\'activer l\'envoi.')->danger()->send();

            return;
        }

        $config->fill([...$donnees, 'modifie_par' => auth()->id()])->save();
        $this->form->fill([...$this->form->getRawState(), 'token' => null]);

        Notification::make()->title('Configuration enregistrée')->success()->send();
    }

    public function tester(): void
    {
        $this->enregistrer();
        $config = SmsConfiguration::actuelle();
        $numero = LeTexto::normaliserNumero($config->numero_test, $config->indicatif);

        if (! $numero) {
            Notification::make()->title('Numéro de test manquant ou invalide')->danger()->send();

            return;
        }
        if (! filled($config->token) || ! filled($config->expediteur)) {
            Notification::make()->title('Token et nom d\'expéditeur obligatoires pour tester')->danger()->send();

            return;
        }

        // Le test appelle toujours l'API, même en mode test
        [$ok, $code, $corps] = LeTexto::appeler($config, $numero, 'Test CRM Greeno : la connexion à LeTexto fonctionne ('.now()->format('d/m/Y H:i').').');

        Notification::make()
            ->title($ok ? 'SMS de test envoyé au '.$numero : 'Échec de l\'envoi'.($code ? " (HTTP {$code})" : ''))
            ->body(\Illuminate\Support\Str::limit($corps, 400))
            ->{$ok ? 'success' : 'danger'}()
            ->persistent()
            ->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form')]);
    }

    public function form(Schema $schema): Schema
    {
        $config = SmsConfiguration::actuelle();

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Compte LeTexto')
                    ->icon(Heroicon::OutlinedKey)
                    ->description('Le token se trouve dans votre espace client LeTexto, rubrique API / Développeurs. Le nom d\'expéditeur doit y être validé.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('token')
                            ->label('Token API (clé)')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(filled($config->token) ? '•••••••• enregistré — laisser vide pour le garder' : 'Collez le token LeTexto')
                            ->helperText(filled($config->token) ? 'Un token est enregistré (chiffré).' : 'Aucun token enregistré.')
                            ->columnSpanFull(),
                        TextInput::make('expediteur')
                            ->label('Nom d\'expéditeur (Sender ID)')
                            ->maxLength(11)
                            ->placeholder('GREENO')
                            ->helperText('11 caractères maximum, sans espace de préférence. Affiché comme expéditeur du SMS.'),
                        TextInput::make('indicatif')
                            ->label('Indicatif pays')
                            ->default('225')
                            ->required()
                            ->maxLength(5)
                            ->helperText('Ajouté devant les numéros à 10 chiffres (0701020304 → 2250701020304).'),
                    ]),
                Section::make('Envoi')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Toggle::make('actif')->label('Envoi des SMS activé')->live()
                            ->helperText('Désactivé : aucun SMS ne part, ils sont seulement inscrits dans l\'historique.'),
                        Toggle::make('mode_test')->label('Mode test (simulation)')->live()
                            ->helperText('Activé : les envois sont simulés (aucun crédit consommé). Le bouton « SMS de test » appelle quand même l\'API.'),
                        TextInput::make('numero_test')->label('Numéro de test')->tel()->placeholder('0701020304')
                            ->helperText('Reçoit le SMS du bouton « Envoyer un SMS de test ».'),
                        TextInput::make('limite_par_envoi')->label('Maximum de SMS par envoi groupé')->numeric()->minValue(1)->maxValue(5000)->default(500),
                        TextEntry::make('etat')
                            ->label('État')
                            ->columnSpanFull()
                            ->state(fn (Get $get) => new HtmlString(match (true) {
                                ! $get('actif') => '<span class="gr-sms-etat gr-ton-pale-gris">Désactivé — aucun SMS n\'est envoyé.</span>',
                                (bool) $get('mode_test') => '<span class="gr-sms-etat gr-ton-pale-orange gr-ton-texte-orange">Mode test — les envois sont simulés.</span>',
                                default => '<span class="gr-sms-etat gr-ton-pale-vert gr-ton-texte-vert">Actif — les SMS partent réellement (crédits LeTexto consommés).</span>',
                            })),
                    ]),
                Section::make('Adresse de l\'API (avancé)')
                    ->icon(Heroicon::OutlinedCodeBracket)
                    ->description('Valeurs par défaut de LeTexto : à ne changer que si LeTexto vous a communiqué une autre adresse.')
                    ->collapsed()
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('url_base')->label('URL de base')->url()->required()->default('https://apis.letexto.com/v1'),
                        TextInput::make('chemin_envoi')->label('Chemin d\'envoi')->required()->default('/messages/send'),
                        Select::make('methode')->label('Méthode HTTP')->options(['GET' => 'GET (paramètres dans l\'URL)', 'POST' => 'POST (JSON)'])->required()->default('GET'),
                        Radio::make('mode_auth')->label('Envoi du token')
                            ->options(['query' => 'Paramètre dans la requête (?token=…)', 'bearer' => 'En-tête Authorization: Bearer'])
                            ->default('query')->required()->live(),
                        TextInput::make('param_token')->label('Nom du paramètre token')->default('token')
                            ->visible(fn (Get $get) => $get('mode_auth') !== 'bearer'),
                        TextInput::make('param_expediteur')->label('Nom du paramètre expéditeur')->default('from')->required(),
                        TextInput::make('param_destinataire')->label('Nom du paramètre destinataire')->default('to')->required(),
                        TextInput::make('param_message')->label('Nom du paramètre message')->default('content')->required(),
                        TextInput::make('url_retour')->label('URL de retour (accusés de réception)')->url()->placeholder('Optionnel')
                            ->columnSpanFull(),
                        TextEntry::make('exemple')
                            ->label('Requête envoyée')
                            ->columnSpanFull()
                            ->state(fn (Get $get) => new HtmlString('<code class="gr-sms-requete">'.e(
                                ($get('methode') ?: 'GET').' '.rtrim((string) $get('url_base'), '/').'/'.ltrim((string) $get('chemin_envoi'), '/')
                                .'?'.($get('mode_auth') === 'bearer' ? '' : ($get('param_token') ?: 'token').'=•••&')
                                .($get('param_expediteur') ?: 'from').'='.($get('expediteur') ?: 'GREENO')
                                .'&'.($get('param_destinataire') ?: 'to').'=2250701020304'
                                .'&'.($get('param_message') ?: 'content').'=Bonjour…'
                            ).'</code>')),
                    ]),
            ]);
    }
}
