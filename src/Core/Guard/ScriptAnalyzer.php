<?php

declare(strict_types=1);

namespace App\Core\Guard;

use App\Core\Connection\Engine;
use App\Core\Guard\Exception\ScriptTooLargeException;
use App\Core\Guard\Exception\UnsplittableScriptException;
use App\Core\Query\QueryMode;
use App\Core\Query\WritePermissions;

/**
 * Juge un script AVANT toute exécution : découpé par `ScriptSplitter`, chaque instruction passe seule par le
 * `StatementGuard` en lecture-écriture, avec les droits de l'appelant. Le rapport dit si le script peut partir
 * (aucun refus), s'il demande une confirmation (une seule pour l'ensemble) et quelle instruction l'arrête.
 */
final class ScriptAnalyzer
{
    private readonly ScriptSplitter $splitter;
    private readonly StatementGuard $guard;

    public function __construct(
        private readonly int $maxStatements = 500,
        private readonly int $maxBytes = 1_000_000,
        ?ScriptSplitter $splitter = null,
        ?StatementGuard $guard = null,
    ) {
        $this->splitter = $splitter ?? new ScriptSplitter();
        $this->guard = $guard ?? new StatementGuard();
    }

    /**
     * @throws UnsplittableScriptException
     * @throws ScriptTooLargeException
     * @throws \InvalidArgumentException   moteur sans SQL (Redis)
     */
    public function analyze(string $script, Engine $engine, WritePermissions $writes): ScriptReport
    {
        if (\strlen($script) > $this->maxBytes) {
            throw ScriptTooLargeException::tooLong($this->maxBytes);
        }

        $pieces = $this->splitter->split($script, SqlDialect::forEngine($engine));
        if (\count($pieces) > $this->maxStatements) {
            throw ScriptTooLargeException::tooManyStatements($this->maxStatements);
        }

        $steps = [];
        foreach ($pieces as $index => $sql) {
            $steps[] = new ScriptStep($index, $sql, $this->guard->check($sql, $engine, QueryMode::ReadWrite, $writes));
        }

        return new ScriptReport($steps);
    }
}
