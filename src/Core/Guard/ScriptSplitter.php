<?php

declare(strict_types=1);

namespace App\Core\Guard;

use App\Core\Guard\Exception\UnsplittableScriptException;

/**
 * Découpe un script SQL en instructions avec le MÊME analyseur que le garde : un point-virgule dans une chaîne, un
 * commentaire, un identifiant ou un corps entre guillemets dollar ne coupe pas. Chaque morceau rendu est ensuite
 * jugé seul par le garde et envoyé seul au moteur : le script n'est jamais transmis en bloc.
 *
 * Dans le doute, tout est refusé : texte jamais refermé, ou point-virgule dans un commentaire exécutable de MySQL
 * (le moteur y lirait deux instructions là où une coupe naïve en verrait une).
 */
final class ScriptSplitter
{
    private readonly SqlScanner $scanner;

    public function __construct(?SqlScanner $scanner = null)
    {
        $this->scanner = $scanner ?? new SqlScanner();
    }

    /**
     * @return list<string> les instructions dans l'ordre, sans espaces autour ni point-virgule final ; les morceaux
     *                      vides ou faits de commentaires seuls sont écartés
     *
     * @throws UnsplittableScriptException
     */
    public function split(string $script, SqlDialect $dialect): array
    {
        $scan = $this->scanner->scan($script, $dialect);
        if (!$scan->complete) {
            throw UnsplittableScriptException::incomplete();
        }
        if ($scan->separatorInExecutableComment) {
            throw UnsplittableScriptException::separatorInExecutableComment();
        }

        $pieces = [];
        $start = 0;
        foreach ([...$scan->separators, \strlen($script)] as $end) {
            $piece = trim(substr($script, $start, $end - $start));
            $start = $end + 1;
            if ('' !== $piece && [] !== $this->scanner->scan($piece, $dialect)->statements) {
                $pieces[] = $piece;
            }
        }

        return $pieces;
    }
}
