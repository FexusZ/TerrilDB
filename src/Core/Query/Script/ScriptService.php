<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\Exception\ScriptTooLargeException;
use App\Core\Guard\Exception\UnsplittableScriptException;
use App\Core\Guard\GuardReason;
use App\Core\Guard\ScriptAnalyzer;
use App\Core\Guard\ScriptReport;
use App\Core\Guard\SqlDialect;
use App\Core\Guard\SqlScanner;
use App\Core\Guard\StatementKind;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Script\Exception\ScriptDryRunUnavailableException;
use App\Core\Query\Script\Exception\ScriptNeedsConfirmationException;
use App\Core\Query\Script\Exception\ScriptRefusedException;
use App\Core\Query\WritePermissions;

/**
 * Le point d'entrée unique de l'exécution d'un SCRIPT (web, API, MCP). Tout est jugé avant la première exécution : un
 * seul refus (instruction interdite, droit manquant) arrête le script entier, une seule confirmation couvre les
 * instructions destructrices. L'exécuteur du moteur s'occupe ensuite de la transaction et de l'arrêt à la première erreur.
 */
final readonly class ScriptService
{
    private SqlScanner $scanner;

    public function __construct(private ScriptAnalyzer $analyzer, ?SqlScanner $scanner = null)
    {
        $this->scanner = $scanner ?? new SqlScanner();
    }

    /**
     * @throws UnsplittableScriptException
     * @throws ScriptTooLargeException
     * @throws UnsupportedOperationException moteur sans SQL (Redis)
     */
    public function preview(string $script, Engine $engine, WritePermissions $writes): ScriptPreview
    {
        if (!$engine->speaksSql()) {
            throw UnsupportedOperationException::for($engine, 'script');
        }

        $report = $this->analyzer->analyze($script, $engine, $writes);

        return new ScriptPreview($report, ScriptCapabilities::for($engine, $this->statements($report, $engine)));
    }

    /**
     * @throws QueryRefusedException            script vide
     * @throws ScriptRefusedException
     * @throws ScriptNeedsConfirmationException
     * @throws ScriptDryRunUnavailableException
     * @throws UnsplittableScriptException
     * @throws ScriptTooLargeException
     */
    public function execute(TargetConnection $target, string $script, ScriptOptions $options, bool $confirmed = false): ScriptResult
    {
        $executor = $target->script();
        $preview = $this->preview($script, $target->engine, $options->writes);
        $report = $preview->report;

        if ([] === $report->steps) {
            throw new QueryRefusedException(GuardReason::EmptyStatement);
        }
        if (null !== $report->firstRefused) {
            throw new ScriptRefusedException($report->firstRefused->verdict->reason ?? GuardReason::UnsupportedStatement, $report->firstRefused->index);
        }
        if ($report->needsConfirmation() && !$confirmed) {
            throw new ScriptNeedsConfirmationException($report->destructiveCount());
        }
        if ($options->dryRun && !$preview->capabilities->dryRunAvailable) {
            throw new ScriptDryRunUnavailableException($preview->capabilities->dryRunUnavailableReason ?? 'no_transaction');
        }

        return $executor->execute($this->statements($report, $target->engine), $options->timeoutMs, $options->dryRun);
    }

    /**
     * @return list<ScriptStatement>
     */
    private function statements(ScriptReport $report, Engine $engine): array
    {
        $statements = [];
        foreach ($report->steps as $step) {
            $kind = $step->verdict->kind ?? StatementKind::Write;
            $statements[] = new ScriptStatement($step->index, $step->sql, $kind, $this->commitsImplicitly($step->sql, $kind, $engine));
        }

        return $statements;
    }

    private function commitsImplicitly(string $sql, StatementKind $kind, Engine $engine): bool
    {
        if (Engine::MySql !== $engine && Engine::MariaDb !== $engine) {
            return false;
        }
        if (StatementKind::Schema === $kind) {
            return true;
        }

        // Une procédure ou un bloc anonyme peuvent contenir n'importe quoi, DDL compris : traités comme un DDL.
        $scan = $this->scanner->scan($sql, SqlDialect::forEngine($engine));

        return \in_array(($scan->statements[0] ?? null)?->first(), ['CALL', 'DO'], true);
    }
}
