<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Formulaires\ConstructeurFormulaire;
use Filament\Schemas\Schema;

/**
 * Formulaire client : entièrement décrit dans Paramètres > Formulaire client
 * (ordre des questions, sections, questions masquées, conditions, listes liées, questions ajoutées).
 */
class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->components((new ConstructeurFormulaire('client'))->sections());
    }
}
