<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

use App\Core\Guard\StatementKind;
use App\Core\Query\Script\ScriptExecutor;
use App\Core\Query\Script\ScriptResult;
use App\Core\Query\Script\ScriptStatus;
use App\Core\Query\Script\ScriptStepResult;
use App\Core\Query\Script\ScriptStepStatus;
use App\Driver\PdoRows;

/**
 * ClickHouse n'a pas de transaction : chaque instruction est immédiate et reste appliquée même si la suivante échoue.
 * Pas de jeu à blanc (rien à annuler) : le service le refuse avant d'arriver ici, et cet exécuteur ne le simule pas.
 */
final readonly class ClickHouseScriptExecutor implements ScriptExecutor
{
    private const TIMEOUT_CODE = 159;
    private const READ_ROW_LIMIT = 1000;

    public function __construct(private ClickHouseHttp $http)
    {
    }

    public function nonTransactionalTables(): array
    {
        return [];
    }

    public function execute(array $statements, int $timeoutMs, bool $dryRun): ScriptResult
    {
        if ($dryRun) {
            throw new \LogicException('ClickHouse has no transaction: a dry run cannot be done.');
        }

        $deadline = hrtime(true) + $timeoutMs * 1_000_000;
        $steps = [];
        $failure = null;
        foreach ($statements as $statement) {
            if (null !== $failure) {
                $steps[] = new ScriptStepResult($statement->index, $statement->sql, ScriptStepStatus::NotRun);
                continue;
            }
            $remaining = intdiv($deadline - hrtime(true), 1_000_000);
            if ($remaining < 1) {
                $steps[] = new ScriptStepResult($statement->index, $statement->sql, ScriptStepStatus::Failed, error: 'The time limit of the script is reached.');
                $failure = 'timeout';
                continue;
            }

            $started = hrtime(true);
            $settings = ['max_execution_time' => $remaining / 1000];
            $reads = StatementKind::Read === $statement->kind || StatementKind::Inspect === $statement->kind;
            if ($reads) {
                $settings += ['max_result_rows' => self::READ_ROW_LIMIT + 1, 'result_overflow_mode' => 'break', 'max_block_size' => self::READ_ROW_LIMIT + 1];
            }
            try {
                $response = $this->http->query($statement->sql, $settings, timeoutSeconds: $remaining / 1000);
            } catch (ClickHouseException $e) {
                $steps[] = new ScriptStepResult($statement->index, $statement->sql, ScriptStepStatus::Failed, error: $e->getMessage(), durationMs: PdoRows::elapsed($started));
                $failure = self::TIMEOUT_CODE === $e->engineCode ? 'timeout' : 'engine_error';
                continue;
            }
            $steps[] = [] !== $response->columns
                ? new ScriptStepResult($statement->index, $statement->sql, ScriptStepStatus::Applied, returnedRows: min(\count($response->rows), self::READ_ROW_LIMIT), durationMs: PdoRows::elapsed($started))
                : new ScriptStepResult($statement->index, $statement->sql, ScriptStepStatus::Applied, affectedRows: $response->writtenRows, durationMs: PdoRows::elapsed($started));
        }

        if (null === $failure) {
            return new ScriptResult(ScriptStatus::Committed, false, $steps, null);
        }
        $anyApplied = [] !== array_filter($steps, static fn (ScriptStepResult $s): bool => ScriptStepStatus::Applied === $s->status);

        return new ScriptResult($anyApplied ? ScriptStatus::Partial : ScriptStatus::Failed, false, $steps, $failure);
    }
}
