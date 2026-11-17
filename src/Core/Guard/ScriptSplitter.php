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
        $spans = [];
        $scan = $this->scanner->scan($script, $dialect, $spans);
        if (!$scan->complete) {
            throw UnsplittableScriptException::incomplete();
        }
        if (SqlDialect::MySql === $dialect && $this->hasDelimiterDirective($script, $spans)) {
            throw UnsplittableScriptException::delimiterDirective();
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

    /**
     * Une ligne qui commence par DELIMITER suivi d'un argument, hors chaîne et commentaire (leurs zones sont blanchies
     * d'abord). Un faux positif (un nom de colonne en tête de ligne suivi d'un mot) coûte un refus clair, jamais un
     * découpage faux.
     *
     * @param list<array{int, int, string}> $spans
     */
    private function hasDelimiterDirective(string $script, array $spans): bool
    {
        foreach ($spans as [$from, $to]) {
            $script = substr_replace($script, preg_replace('/[^\r\n]/', ' ', substr($script, $from, $to - $from)) ?? '', $from, $to - $from);
        }

        return 1 === preg_match('/^[ \t]*DELIMITER[ \t]+\S/im', $script);
    }
}
