<?php

namespace App\Filament\Clusters\Parametres\Pages;

use App\Filament\Clusters\Parametres\ParametresCluster;
use App\Models\FormulaireChamp;
use App\Photos\GoogleDrive;
use App\Photos\ParametresPhotos;
use BackedEnum;
use Filament\Actions\Action;
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
use Throwable;

/**
 * Paramètres > Photos : pour chaque table qui contient des photos (KYC, dimensionnement…),
 * le dossier Google Drive qui en reçoit une copie et le modèle du nom des fichiers.
 */
class PhotosParametres extends Page
{
    protected static ?string $cluster = ParametresCluster::class;

    protected static ?string $slug = 'photos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Photos';

    protected static ?string $title = 'Photos';

    protected static ?int $navigationSort = 0;

    /** @var array<string, array{lien: ?string, modele: string, actif: bool}> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(ParametresPhotos::tous());
    }

    public function getSubheading(): ?string
    {
        return 'Pour chaque formulaire avec des photos : dossier Google Drive qui reçoit une copie, et nom des fichiers. Les photos restent aussi dans le CRM.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enregistrer')
                ->label('Enregistrer')
                ->icon(Heroicon::OutlinedCheck)
                ->action(fn () => $this->enregistrer()),
        ];
    }

    public function enregistrer(): void
    {
        $donnees = $this->form->getState();

        foreach ($donnees as $formulaire => $parametres) {
            if (($parametres['actif'] ?? false) && ! ParametresPhotos::idDossierDrive($parametres['lien'] ?? null)) {
                Notification::make()
                    ->title(ParametresPhotos::libelle($formulaire).' : lien Drive manquant ou invalide')
                    ->body('Collez le lien du dossier (https://drive.google.com/drive/folders/…) ou désactivez l\'envoi.')
                    ->danger()
                    ->send();

                return;
            }
        }

        ParametresPhotos::enregistrer($donnees);
        Notification::make()->title('Paramètres des photos enregistrés')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Connexion à Google Drive')
                ->icon(Heroicon::OutlinedCloud)
                ->compact()
                ->schema([
                    TextEntry::make('connexion')
                        ->hiddenLabel()
                        ->state(fn () => self::etatConnexion()),
                ]),
            EmbeddedSchema::make('form'),
            Section::make('Derniers envois vers Drive')
                ->icon(Heroicon::OutlinedClock)
                ->compact()
                ->collapsed()
                ->schema([
                    TextEntry::make('journal')
                        ->hiddenLabel()
                        ->state(fn () => self::journal()),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components(collect(ParametresPhotos::formulairesAvecPhotos())->map(fn (string $formulaire) => Section::make(ParametresPhotos::libelle($formulaire))
                ->description('Table « '.$formulaire.' » · '.FormulaireChamp::pour($formulaire)->where('type', 'photo')->pluck('libelle')->implode(', '))
                ->icon(Heroicon::OutlinedPhoto)
                ->columns(['default' => 1, 'lg' => 2])
                ->schema([
                    Toggle::make("{$formulaire}.actif")
                        ->label('Envoyer une copie des photos dans Google Drive')
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make("{$formulaire}.lien")
                        ->label('Lien du dossier Google Drive')
                        ->placeholder('https://drive.google.com/drive/folders/…')
                        ->required(fn (Get $get) => (bool) $get("{$formulaire}.actif"))
                        ->helperText(fn () => ($email = GoogleDrive::emailCompteService())
                            ? 'Partagez ce dossier avec '.$email.' (Gestionnaire de contenu).'
                            : 'Le dossier doit être partagé avec le compte de service Google (voir « Connexion »).')
                        ->suffixAction(
                            Action::make('tester_'.$formulaire)
                                ->icon(Heroicon::OutlinedSignal)
                                ->tooltip('Vérifier l\'accès au dossier')
                                ->action(fn (Get $get) => self::tester($get("{$formulaire}.lien"))),
                        ),
                    TextInput::make("{$formulaire}.modele")
                        ->label('Modèle du nom des fichiers')
                        ->default(ParametresPhotos::MODELE_PAR_DEFAUT)
                        ->required()
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get) => new HtmlString(
                            'Exemple : <b>'.e(self::exemple($formulaire, (string) $get("{$formulaire}.modele"))).'</b><br>'
                            .'Variables : '.collect(ParametresPhotos::VARIABLES)->map(fn ($aide, $variable) => '<code title="'.e($aide).'">'.e($variable).'</code>')->implode(' '))),
                ]))->all());
    }

    private static function exemple(string $formulaire, string $modele): string
    {
        $champ = FormulaireChamp::pour($formulaire)->firstWhere('type', 'photo') ?? new FormulaireChamp(['cle' => 'photo', 'libelle' => 'Photo']);
        $client = new \App\Models\Client(['nom_client' => 'KONE', 'prenoms_client' => 'Adama']);
        $client->id_client = '1000';

        // Aperçu avec le modèle en cours de saisie (pas encore enregistré)
        return ParametresPhotos::nomFichier($formulaire, $champ, $client, modele: $modele ?: null).'.jpg';
    }

    private static function tester(?string $lien): void
    {
        $dossier = ParametresPhotos::idDossierDrive($lien);

        if (! $dossier) {
            Notification::make()->title('Lien de dossier invalide')->body('Collez le lien du dossier Drive (…/folders/…).')->danger()->send();

            return;
        }

        try {
            $nom = app(GoogleDrive::class)->nomDossier($dossier);
            Notification::make()->title('Dossier accessible : '.$nom)->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Dossier inaccessible')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    private static function etatConnexion(): HtmlString
    {
        if (! GoogleDrive::estConfigure()) {
            return new HtmlString('<p><b style="color: var(--warning-600)">Pas encore configurée.</b> '
                .'Créez un compte de service Google (API Google Drive activée), téléchargez sa clé JSON et placez-la dans '
                .'<code>'.e(GoogleDrive::cheminCle()).'</code>. '
                .'Les dossiers doivent se trouver dans un <b>Drive partagé</b> et être partagés avec l\'e-mail du compte de service.</p>');
        }

        return new HtmlString('<p><b style="color: var(--success-600)">Configurée.</b> Compte de service : <code>'.e(GoogleDrive::emailCompteService()).'</code> '
            .'— partagez chaque dossier avec cette adresse.</p>');
    }

    private static function journal(): HtmlString
    {
        $lignes = ParametresPhotos::journal();

        if (! $lignes) {
            return new HtmlString('<p class="gr-vide-valeur">Aucun envoi pour le moment.</p>');
        }

        return new HtmlString('<ul class="gr-flux">'.collect($lignes)->map(fn ($l) => '<li class="gr-ton-'.($l['ok'] ? 'succes' : 'danger').'">'
            .'<span class="gr-flux-point"></span>'
            .'<span><b>'.e($l['fichier']).'</b><small>'.e(collect([$l['date'], ParametresPhotos::libelle($l['formulaire']), $l['ok'] ? 'envoyée' : $l['detail']])->filter()->implode(' · ')).'</small></span>'
            .'</li>')->implode('').'</ul>');
    }
}
