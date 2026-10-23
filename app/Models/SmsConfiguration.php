<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Configuration de l'API SMS (LeTexto) : une seule ligne. Le token est chiffré en base. */
class SmsConfiguration extends Model
{
    protected $table = 'sms_configuration';

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'actif' => 'boolean',
            'mode_test' => 'boolean',
            'limite_par_envoi' => 'integer',
        ];
    }

    public static function installe(): bool
    {
        return Cache::remember('sms-installe', now()->addMinutes(10),
            fn () => Schema::hasTable('sms_configuration') && Schema::hasTable('sms_modeles') && Schema::hasTable('sms_messages'));
    }

    public static function actuelle(): self
    {
        return static::query()->first() ?? new static([
            'fournisseur' => 'letexto', 'url_base' => 'https://apis.letexto.com/v1', 'chemin_envoi' => '/messages/send',
            'methode' => 'GET', 'mode_auth' => 'query', 'indicatif' => '225',
            'param_token' => 'token', 'param_expediteur' => 'from', 'param_destinataire' => 'to', 'param_message' => 'content',
            'actif' => false, 'mode_test' => true, 'limite_par_envoi' => 500,
        ]);
    }

    /** Prête à envoyer de vrais SMS (active, token et expéditeur renseignés) ? */
    public function prete(): bool
    {
        return $this->actif && filled($this->token) && filled($this->expediteur);
    }
}
