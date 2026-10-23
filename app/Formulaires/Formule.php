<?php

namespace App\Formulaires;

use InvalidArgumentException;

/**
 * Formule de calcul d'une question « Calcul » (Paramètres > Formulaire …).
 *
 * Syntaxe : nombres, questions entre accolades, + - * / et parenthèses, et les fonctions
 * min(a; b…), max(a; b…), abs(a), arrondi(a; décimales). Exemple :
 *     {prof_reglage_pomp_tdh} + {haut_elevation_verticale_tdh} + 0,1 * {dist_src_eau_et_reservoir_tdh}
 * Une question sans réponse vaut 0. Aucun code n'est exécuté : la formule est analysée puis calculée.
 */
class Formule
{
    private const FONCTIONS = ['min', 'max', 'abs', 'arrondi'];

    /** Clés des questions utilisées dans la formule. */
    public static function variables(?string $formule): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/i', (string) $formule, $m);

        return array_values(array_unique($m[1]));
    }

    /** Message d'erreur si la formule est mal écrite, sinon null. */
    public static function erreur(?string $formule): ?string
    {
        try {
            self::evaluer((string) $formule, fn () => 1.0);

            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param  callable(string): mixed  $valeur  valeur d'une question à partir de sa clé
     */
    public static function evaluer(string $formule, callable $valeur): ?float
    {
        if (trim($formule) === '') {
            return null;
        }

        $jetons = self::decouper($formule);
        $position = 0;
        $resultat = self::expression($jetons, $position, $valeur);

        if ($position < count($jetons)) {
            throw new InvalidArgumentException('Élément inattendu : « '.$jetons[$position][1].' ».');
        }

        return is_finite($resultat) ? round($resultat, 4) : null;
    }

    /** @return list<array{0: string, 1: string}> [type, valeur] */
    private static function decouper(string $formule): array
    {
        $jetons = [];
        $longueur = strlen($formule);

        for ($i = 0; $i < $longueur;) {
            $c = $formule[$i];

            if (ctype_space($c)) {
                $i++;
            } elseif ($c === '{') {
                $fin = strpos($formule, '}', $i);
                if ($fin === false) {
                    throw new InvalidArgumentException('Accolade « { » non refermée.');
                }
                $cle = trim(substr($formule, $i + 1, $fin - $i - 1));
                if (! preg_match('/^[a-z0-9_]+$/i', $cle)) {
                    throw new InvalidArgumentException("Nom de question invalide : « {$cle} ».");
                }
                $jetons[] = ['variable', $cle];
                $i = $fin + 1;
            } elseif (ctype_digit($c) || (($c === '.' || $c === ',') && $i + 1 < $longueur && ctype_digit($formule[$i + 1]))) {
                preg_match('/\G[0-9]*(?:[.,][0-9]+)?/', $formule, $m, 0, $i);
                $jetons[] = ['nombre', str_replace(',', '.', $m[0])];
                $i += strlen($m[0]);
            } elseif (ctype_alpha($c)) {
                preg_match('/\G[a-z]+/i', $formule, $m, 0, $i);
                $nom = strtolower($m[0]);
                if (! in_array($nom, self::FONCTIONS, true)) {
                    throw new InvalidArgumentException("Fonction inconnue : « {$m[0]} » (possibles : ".implode(', ', self::FONCTIONS).').');
                }
                $jetons[] = ['fonction', $nom];
                $i += strlen($m[0]);
            } elseif (str_contains('+-*/();', $c)) {
                $jetons[] = ['symbole', $c];
                $i++;
            } else {
                throw new InvalidArgumentException("Caractère non autorisé : « {$c} ».");
            }
        }

        return $jetons;
    }

    // expression := terme (('+' | '-') terme)*
    private static function expression(array $jetons, int &$p, callable $valeur): float
    {
        $total = self::terme($jetons, $p, $valeur);

        while (isset($jetons[$p]) && $jetons[$p][0] === 'symbole' && in_array($jetons[$p][1], ['+', '-'], true)) {
            $operateur = $jetons[$p++][1];
            $droite = self::terme($jetons, $p, $valeur);
            $total = $operateur === '+' ? $total + $droite : $total - $droite;
        }

        return $total;
    }

    // terme := facteur (('*' | '/') facteur)*
    private static function terme(array $jetons, int &$p, callable $valeur): float
    {
        $total = self::facteur($jetons, $p, $valeur);

        while (isset($jetons[$p]) && $jetons[$p][0] === 'symbole' && in_array($jetons[$p][1], ['*', '/'], true)) {
            $operateur = $jetons[$p++][1];
            $droite = self::facteur($jetons, $p, $valeur);
            $total = $operateur === '*' ? $total * $droite : ($droite == 0.0 ? INF : $total / $droite);
        }

        return $total;
    }

    // facteur := nombre | {question} | fonction '(' arguments ')' | '(' expression ')' | '-' facteur
    private static function facteur(array $jetons, int &$p, callable $valeur): float
    {
        $jeton = $jetons[$p] ?? null;

        if ($jeton === null) {
            throw new InvalidArgumentException('La formule se termine trop tôt.');
        }

        if ($jeton === ['symbole', '-']) {
            $p++;

            return -self::facteur($jetons, $p, $valeur);
        }

        if ($jeton[0] === 'nombre') {
            $p++;

            return (float) $jeton[1];
        }

        if ($jeton[0] === 'variable') {
            $p++;
            $v = $valeur($jeton[1]);

            return is_numeric(is_string($v) ? str_replace(',', '.', $v) : $v) ? (float) str_replace(',', '.', (string) $v) : 0.0;
        }

        if ($jeton === ['symbole', '(']) {
            $p++;
            $resultat = self::expression($jetons, $p, $valeur);
            self::attendre($jetons, $p, ')');

            return $resultat;
        }

        if ($jeton[0] === 'fonction') {
            $p++;
            self::attendre($jetons, $p, '(');
            $arguments = [self::expression($jetons, $p, $valeur)];
            while (($jetons[$p] ?? null) === ['symbole', ';']) {
                $p++;
                $arguments[] = self::expression($jetons, $p, $valeur);
            }
            self::attendre($jetons, $p, ')');

            return match ($jeton[1]) {
                'min' => min($arguments),
                'max' => max($arguments),
                'abs' => abs($arguments[0]),
                'arrondi' => round($arguments[0], (int) ($arguments[1] ?? 0)),
            };
        }

        throw new InvalidArgumentException('Élément inattendu : « '.$jeton[1].' ».');
    }

    private static function attendre(array $jetons, int &$p, string $symbole): void
    {
        if (($jetons[$p] ?? null) !== ['symbole', $symbole]) {
            throw new InvalidArgumentException("« {$symbole} » attendu.");
        }
        $p++;
    }
}
