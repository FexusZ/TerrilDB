<?php

declare(strict_types=1);

namespace App\Driver;

use App\Core\Guard\StatementKind;
use App\Core\Query\Script\ScriptExecutor;
use App\Core\Query\Script\ScriptResult;
use App\Core\Query\Script\ScriptStatement;
use App\Core\Query\Script\ScriptStatus;
use App\Core\Query\Script\ScriptStepResult;
use App\Core\Query\Script\ScriptStepStatus;
use Doctrine\DBAL\Connection;

/**
 * Le script de PostgreSQL, MySQL et MariaDB : UNE transaction pour tout le script, arrêt à la première erreur, annulation
 * de ce que le moteur n'a pas déjà validé de lui-même (un DDL MySQL valide tout ce qui précède, puis lui-même : `durable`
 * dit jusqu'où ; une écriture dans une table sans transaction reste aussi et se rapporte comme appliquée). Le délai est global : chaque instruction reçoit ce qu'il reste. Le texte du moteur n'est gardé que pour
 * l'instruction en échec.
 */
abstract class PdoScriptExecutor implements ScriptExecutor
{
    private const READ_ROW_LIMIT = 1000;

    public function __construct(protected readonly Connection $connection)
    {
    }

    public function nonTransactionalTables(): array
    {
        return [];
    }

    final public function execute(array $statements, int $timeoutMs, bool $dryRun): ScriptResult
    {
        $pdo = $this->connection->getNativeConnection();
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('The script executor needs a PDO connection.');
        }

        $deadline = hrtime(true) + $timeoutMs * 1_000_000;
        /** @var list<ScriptStepResult> $steps */
        $steps = [];
        $durable = -1;
        $failure = null;

        try {
            $this->begin($pdo);
            foreach ($statements as $position => $statement) {
                $remaining = intdiv($deadline - hrtime(true), 1_000_000);
                if ($remaining < 1) {
                    $steps[] = new ScriptStepResult($statement->index, $statement->sql, ScriptStepStatus::Failed, error: 'The time limit of the script is reached.');
                    $failure = 'timeout';
                    break;
                }
                if ($statement->commitsImplicitly) {
                    $durable = $position - 1;
                }

                try {
                    $steps[] = $this->runOne($pdo, $statement, $remaining);
                } catch (\PDOException $e) {
                    $steps[] = new ScriptStepResult($statement->index, $statement->sql, ScriptStepStatus::Failed, error: $this->message($e), errorCode: self::code($e));
                    $failure = $this->isTimeout($e) ? 'timeout' : 'engine_error';
                    break;
                }
                if ($statement->commitsImplicitly) {
                    $durable = $position;
                    // La transaction est terminée : sans en rouvrir une, la suite partirait en validation automatique.
                    $this->begin($pdo);
                }
            }

            if (null === $failure && !$dryRun) {
                $pdo->exec('COMMIT');
            } else {
                $this->rollBack($pdo);
            }
        } catch (\PDOException $e) {
            // Début ou validation refusés : rien de sûr n'est resté, on le dit plutôt que de laisser croire à un succès.
            $this->rollBack($pdo);
            $steps = $this->withFailure($steps, $statements, $e);
            $failure = 'engine_error';
        } catch (\Throwable $e) {
            $this->rollBack($pdo);

            throw $e;
        }

        return $this->report($statements, $steps, $durable, $failure, $dryRun);
    }

    /**
     * Ouvre une lecture dont on ne gardera que `$limit` lignes. Par défaut le moteur streame et la lecture s'arrête d'elle-même
     * (MySQL, non tamponné) ; PostgreSQL rapatrie tout un résultat avant de le rendre : il passe par un curseur serveur.
     */
    protected function openRead(\PDO $pdo, string $sql, int $limit): \PDOStatement|false
    {
        return $pdo->query($sql);
    }

    protected function closeRead(\PDO $pdo): void
    {
    }

    abstract protected function begin(\PDO $pdo): void;

    abstract protected function limit(\PDO $pdo, int $remainingMs): void;

    abstract protected function isTimeout(\PDOException $e): bool;

    private static function code(\PDOException $e): ?string
    {
        $info = \is_array($e->errorInfo) ? $e->errorInfo : [];
        $state = \is_string($info[0] ?? null) && 1 === preg_match('/^[0-9A-Z]{5}$/', $info[0]) ? $info[0] : null;

        return null !== $state && \is_int($info[1] ?? null) ? $state.'/'.$info[1] : $state;
    }

    protected function message(\PDOException $e): string
    {
        $info = \is_array($e->errorInfo) ? $e->errorInfo : [];

        return trim((string) preg_replace('/^ERROR:\s+/', '', \is_string($info[2] ?? null) ? $info[2] : $e->getMessage()));
    }

    private function runOne(\PDO $pdo, ScriptStatement $statement, int $remainingMs): ScriptStepResult
    {
        $started = hrtime(true);
        $this->limit($pdo, $remainingMs);
        $result = StatementKind::Read === $statement->kind ? $this->openRead($pdo, $statement->sql, self::READ_ROW_LIMIT + 1) : $pdo->query($statement->sql);
        \assert($result instanceof \PDOStatement);

        if ($result->columnCount() > 0) {
            $rows = PdoRows::read($result, self::READ_ROW_LIMIT, $started);
            $this->closeRead($pdo);

            return new ScriptStepResult($statement->index, $statement->sql, ScriptStepStatus::Applied, returnedRows: $rows->rowCount(), durationMs: PdoRows::elapsed($started), truncated: $rows->truncated);
        }

        return new ScriptStepResult($statement->index, $statement->sql, ScriptStepStatus::Applied, affectedRows: $result->rowCount(), durationMs: PdoRows::elapsed($started));
    }

    private function rollBack(\PDO $pdo): void
    {
        try {
            $pdo->exec('ROLLBACK');
        } catch (\Throwable) {
            // Connexion perdue : l'appelant la ferme, le moteur annule seul.
        }
    }

    /**
     * @param list<ScriptStepResult> $steps
     * @param list<ScriptStatement>  $statements
     *
     * @return list<ScriptStepResult>
     */
    private function withFailure(array $steps, array $statements, \PDOException $e): array
    {
        $position = \count($steps);
        if ($position >= \count($statements)) {
            // Tout est passé, c'est la validation finale qui a échoué : la dernière instruction porte l'erreur.
            $last = array_pop($steps);
            \assert(null !== $last);

            return [...$steps, new ScriptStepResult($last->index, $last->sql, ScriptStepStatus::Failed, error: $this->message($e), errorCode: self::code($e))];
        }

        return $steps;
    }

    /**
     * @param list<ScriptStatement>  $statements
     * @param list<ScriptStepResult> $steps
     */
    private function report(array $statements, array $steps, int $durable, ?string $failure, bool $dryRun): ScriptResult
    {
        $report = [];
        foreach ($steps as $position => $step) {
            $report[] = ScriptStepStatus::Failed === $step->status ? $step : $step->with($position <= $durable || (null === $failure && !$dryRun) || ($statements[$position]->writesNonTransactional ?? false) ? ScriptStepStatus::Applied : ScriptStepStatus::RolledBack);
        }
        foreach (\array_slice($statements, \count($steps)) as $statement) {
            $report[] = new ScriptStepResult($statement->index, $statement->sql, ScriptStepStatus::NotRun);
        }

        if (null === $failure) {
            return new ScriptResult($dryRun ? ScriptStatus::DryRun : ScriptStatus::Committed, $dryRun, $report, null);
        }
        $anyDurable = [] !== array_filter($report, static fn (ScriptStepResult $s): bool => ScriptStepStatus::Applied === $s->status);

        return new ScriptResult($anyDurable ? ScriptStatus::Partial : ScriptStatus::RolledBack, $dryRun, $report, $failure);
    }
}
