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
use App\Core\Query\QueryService;
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
    /**
     * @param list<string> $nonTransactionalTables voir ScriptExecutor::nonTransactionalTables (MySQL/MariaDB)
     */
    public function preview(string $script, Engine $engine, WritePermissions $writes, array $nonTransactionalTables = [], bool $lenient = false): ScriptPreview
    {
        if (!$engine->speaksSql()) {
            throw UnsupportedOperationException::for($engine, 'script');
        }

        $report = $this->analyzer->analyze($script, $engine, $writes, $lenient);

        return new ScriptPreview($report, ScriptCapabilities::for($engine, $this->statements($report, $engine, $nonTransactionalTables)));
    }

    /**
     * Comme `preview`, avec ce que la cible dit d'elle-même (tables sans transaction de MySQL/MariaDB touchées par le script).
     *
     * @throws UnsplittableScriptException
     * @throws ScriptTooLargeException
     * @throws UnsupportedOperationException
     */
    public function previewFor(TargetConnection $target, string $script, WritePermissions $writes, bool $lenient = false): ScriptPreview
    {
        $report = $this->preview($script, $target->engine, $writes, [], $lenient)->report;

        return $this->preview($script, $target->engine, $writes, $this->nonTransactionalTables($target->script(), $target->engine, $report), $lenient);
    }

    /**
     * @throws QueryRefusedException            script vide
     * @throws ScriptRefusedException
     * @throws ScriptNeedsConfirmationException
     * @throws ScriptDryRunUnavailableException
     * @throws UnsplittableScriptException
     * @throws ScriptTooLargeException
     * @throws UnsupportedOperationException
     */
    public function execute(TargetConnection $target, string $script, ScriptOptions $options, bool $confirmed = false): ScriptResult
    {
        $executor = $target->script();
        $report = $this->preview($script, $target->engine, $options->writes, [], $options->lenient)->report;
        $unsafe = $this->nonTransactionalTables($executor, $target->engine, $report);
        $preview = $this->preview($script, $target->engine, $options->writes, $unsafe, $options->lenient);

        if ($options->lenient && !$confirmed && [] !== $report->steps && null === $report->firstRefused) {
            $this->warnAboutStreams($target, $report);
        }
        $this->assertRunnable($report, $preview, $options->dryRun, $confirmed);

        return $executor->execute($this->statements($report, $target->engine, $unsafe), $options->timeoutMs, $options->dryRun, $options->progress);
    }

    /**
     * Le jugement d'un script SANS cible ouverte (le lancement d'une exécution détachée : la cible n'est jointe que par le worker) :
     * mêmes refus, même confirmation unique, même jeu à blanc que `execute`, sans la lecture des tables sans transaction de MySQL/MariaDB
     * (qui exige la cible ; le worker la refait au moment d'exécuter). Rend le compte rendu du garde.
     *
     * @throws QueryRefusedException            script vide
     * @throws ScriptRefusedException
     * @throws ScriptNeedsConfirmationException
     * @throws ScriptDryRunUnavailableException
     * @throws UnsplittableScriptException
     * @throws ScriptTooLargeException
     * @throws UnsupportedOperationException
     */
    public function judge(string $script, Engine $engine, WritePermissions $writes, bool $dryRun, bool $confirmed, bool $lenient = false): ScriptReport
    {
        $preview = $this->preview($script, $engine, $writes, [], $lenient);
        $this->assertRunnable($preview->report, $preview, $dryRun, $confirmed);

        return $preview->report;
    }

    /**
     * Vitess (console seulement) : une instruction de structure qui nomme une table source d'un flux (Materialize, MoveTables) n'y sera pas
     * propagée ; l'avertissement le dit, une seule confirmation couvre le script.
     *
     * @throws ScriptNeedsConfirmationException
     */
    private function warnAboutStreams(TargetConnection $target, ScriptReport $report): void
    {
        $count = 0;
        $streams = [];
        foreach ($report->steps as $step) {
            if (StatementKind::Schema !== $step->verdict->kind) {
                continue;
            }
            $hits = QueryService::streamsTouched($target, $step->sql);
            if ([] !== $hits) {
                ++$count;
                foreach ($hits as $hit) {
                    $streams[$hit->workflow."\0".strtolower($hit->table)] = $hit;
                }
            }
        }
        if ($count > 0) {
            throw new ScriptNeedsConfirmationException($count, 'stream_source', array_values($streams));
        }
    }

    /**
     * @throws QueryRefusedException
     * @throws ScriptRefusedException
     * @throws ScriptNeedsConfirmationException
     * @throws ScriptDryRunUnavailableException
     */
    private function assertRunnable(ScriptReport $report, ScriptPreview $preview, bool $dryRun, bool $confirmed): void
    {
        if ([] === $report->steps) {
            throw new QueryRefusedException(GuardReason::EmptyStatement);
        }
        if (null !== $report->firstRefused) {
            throw new ScriptRefusedException($report->firstRefused->verdict->reason ?? GuardReason::UnsupportedStatement, $report->firstRefused->index);
        }
        if ($report->needsConfirmation() && !$confirmed) {
            throw new ScriptNeedsConfirmationException($report->destructiveCount());
        }
        if ($dryRun && !$preview->capabilities->dryRunAvailable) {
            throw new ScriptDryRunUnavailableException($preview->capabilities->dryRunUnavailableReason ?? 'no_transaction');
        }
    }

    /**
     * Seulement quand cela compte : MySQL/MariaDB et au moins une instruction qui écrit.
     *
     * @return list<string>
     */
    private function nonTransactionalTables(ScriptExecutor $executor, Engine $engine, ScriptReport $report): array
    {
        if (Engine::MySql !== $engine && Engine::MariaDb !== $engine) {
            return [];
        }
        foreach ($report->steps as $step) {
            if (StatementKind::Write === $step->verdict->kind) {
                return $executor->nonTransactionalTables();
            }
        }

        return [];
    }

    /**
     * @param list<string> $nonTransactionalTables
     *
     * @return list<ScriptStatement>
     */
    private function statements(ScriptReport $report, Engine $engine, array $nonTransactionalTables = []): array
    {
        $statements = [];
        foreach ($report->steps as $step) {
            $kind = $step->verdict->kind ?? StatementKind::Write;
            $statements[] = new ScriptStatement($step->index, $step->sql, $kind, $this->commitsImplicitly($step->sql, $kind, $engine), StatementKind::Write === $kind && self::mentionsAny($step->sql, $nonTransactionalTables));
        }

        return $statements;
    }

    /**
     * Le nom d'une table apparaît dans l'instruction (sans casse, comme mot entier, guillemets ou non, qualifié ou non). Large
     * à dessein : mieux vaut refuser un jeu à blanc de trop qu'en promettre un faux.
     *
     * @param list<string> $names
     */
    private static function mentionsAny(string $sql, array $names): bool
    {
        foreach ($names as $name) {
            if (1 === preg_match('/(?<![\w$])'.preg_quote($name, '/').'(?![\w$])/iu', $sql)) {
                return true;
            }
        }

        return false;
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
