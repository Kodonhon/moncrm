<?php

namespace Tests\Concerns;

use App\Models\Client;
use Illuminate\Support\Facades\DB;

/** Jeu minimal de tables de référence, aux mêmes identifiants que les données GREENO. */
trait DonneesReference
{
    protected function creerReferences(): void
    {
        DB::table('genre')->insert([['id_genre' => 0, 'lib_genre' => 'Féminin', 'vis_genre' => 1], ['id_genre' => 1, 'lib_genre' => 'Masculin', 'vis_genre' => 1]]);
        DB::table('entite')->insert([['id_entite' => 0, 'lib_entite' => 'All'], ['id_entite' => 1, 'lib_entite' => 'Greeno'], ['id_entite' => 2, 'lib_entite' => 'Tevia']]);
        DB::table('src_client')->insert([
            ['id_src_client' => 1, 'lib_src_client' => 'Televente'],
            ['id_src_client' => 2, 'lib_src_client' => 'Marketing'],
            ['id_src_client' => 3, 'lib_src_client' => 'Terrain'],
        ]);
        DB::table('type_piece_id')->insert([['id_type_piece_id' => 1, 'lib_type_piece_id' => 'CNI']]);
        DB::table('site_shop')->insert([['id_site_shop' => 0, 'lib_site_shop' => 'Vide'], ['id_site_shop' => 5, 'lib_site_shop' => 'SHOP GAGNOA'], ['id_site_shop' => 9, 'lib_site_shop' => 'SHOP DALOA']]);
        DB::table('ville')->insert([['id_ville' => 13, 'lib_ville' => 'GAGNOA'], ['id_ville' => 17, 'lib_ville' => 'DALOA']]);
        DB::table('canal_vente')->insert([
            ['id_canal_vente' => 1, 'lib_canal_vente' => 'Direct', 'id_src_client' => 3, 'id_entite' => 0],
            ['id_canal_vente' => 2, 'lib_canal_vente' => 'Réseaux sociaux', 'id_src_client' => 2, 'id_entite' => 0],
        ]);
        DB::table('usage_kit')->insert([['id_usg_kit' => 1, 'lib_usg_kit' => 'Agriculture'], ['id_usg_kit' => 2, 'lib_usg_kit' => 'Usage domestique']]);
        DB::table('src_energie')->insert([['id_src_energie' => 0, 'lib_src_energie' => 'Aucune'], ['id_src_energie' => 1, 'lib_src_energie' => 'Solaire']]);
        DB::table('produit')->insert([['id_prod' => 1, 'lib_prod' => 'Pompe TEVIA 1 CV', 'debit_prod' => 5]]);
        DB::table('type_vente')->insert([['id_type_vente' => 1, 'lib_type_vente' => 'Payg'], ['id_type_vente' => 2, 'lib_type_vente' => 'Cash']]);
        DB::table('agent')->insert([
            ['id_agent' => 1, 'nom_agent' => 'Koffi Yao', 'id_site_shop' => 5, 'visibilite_agent' => 1],
            ['id_agent' => 2, 'nom_agent' => 'Agent Parti', 'id_site_shop' => 9, 'visibilite_agent' => 0],
        ]);
        DB::table('localite_commune')->insert([
            ['id_localite_commune' => 1, 'lib_localite' => 'DALOA', 'id_ville' => 17, 'id_site_shop' => 9],
            ['id_localite_commune' => 2, 'lib_localite' => 'GAGNOA', 'id_ville' => 13, 'id_site_shop' => 5],
            ['id_localite_commune' => 3, 'lib_localite' => 'GUIBEROUA', 'id_ville' => 13, 'id_site_shop' => 5],
        ]);
    }

    protected function creerClient(array $attributs = []): Client
    {
        return Client::create($attributs + [
            'nom_client' => 'TEST',
            'prenoms_client' => 'Client',
            'contact1_client' => '2250102030405',
            'id_localite_commune' => 2,
            'id_canal_vente' => 1,
            'genre_client' => 1,
            'entite_id_client' => 1,
        ])->refresh();
    }
}
