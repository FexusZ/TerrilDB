<?php

declare(strict_types=1);

namespace App\ScriptRun;

use App\Core\Driver\DriverRegistry;
use App\Core\Driver\TargetConnection;
use App\Core\Query\Script\ScriptOptions;
use App\Core\Query\Script\ScriptService;
use App\Entity\Exception\InvalidScriptRunException;
use App\Entity\ScriptRun;
use App\Entity\ScriptRunStatus;
use App\Repository\ScriptRunRepository;
use Psr\Clock\ClockInterface;

/**
 * Joue UNE exécution détachée de bout en bout (#169) : ouvre la cible avec les identifiants du message (sans clé d'utilisateur),
 * exécute par `ScriptService` (même garde, mêmes exécuteurs que la console), suit l'avancement dans la base interne et note le
 * rapport final. N'échoue jamais pour une raison de script ou de cible : l'exécution se termine `failed` et c'est tout ;
 * seule une panne imprévue remonte (après avoir terminé l'exécution, au mieux).
 */
final readonly class ScriptRunExecutor
{
    public function __construct(
        private ScriptRunRepository $runs,
        private DriverRegistry $drivers,
        private ScriptService $scripts,
        private ClockInterface $clock,
        /** Point d'observation des tests, voir `ScriptRunProgress`. */
        private ?\Closure $afterStatement = null,
    ) {
    }

    public function run(ScriptRunRequest $request): void
    {
        $run = $this->runs->find($request->runId);
        // Une exécution inconnue, déjà lancée ou terminée n'est jamais rejouée : un message rejoué n'écrit rien deux fois.
        if (!$run instanceof ScriptRun || ScriptRunStatus::Queued !== $run->getStatus()) {
            return;
        }
        $run->start($this->now());
        $this->runs->save($run);

        $target = null;
        try {
            $target = $this->drivers->for($request->engine)->connect($request->engine, $request->parameters);
            $result = $this->scripts->execute(
                $target,
                $request->script,
                new ScriptOptions($request->writes, $request->timeoutMs, $request->dryRun, new ScriptRunProgress($run, $this->runs, $this->clock, $this->afterStatement)),
                $request->confirmed,
            );
            $this->finish($run, ScriptRunReport::status($result), ScriptRunReport::statements($result));
        } catch (\DomainException|\RuntimeException) {
            // Cible injoignable, script refusé par le garde, droit manquant… : rien à rejouer, l'exécution se dit simplement échouée.
            $this->finish($run, ScriptRunStatus::Failed, null);
        } catch (\Throwable $e) {
            $this->finish($run, ScriptRunStatus::Failed, null);

            throw $e;
        } finally {
            $this->close($target);
        }
    }

    /**
     * @param list<array{rank: int, status: string, affectedRows: int|null, durationMs: int, sqlstate: string|null}>|null $statements
     */
    private function finish(ScriptRun $run, ScriptRunStatus $status, ?array $statements): void
    {
        if ($run->getStatus()->isTerminal()) {
            return;
        }
        try {
            $run->finish($status, $this->now(), $statements);
        } catch (InvalidScriptRunException) {
            // Un rapport qui ne tient pas dans le suivi (nombre d'instructions différent du prévu) : on garde la fin, pas le détail.
            $run->finish($status, $this->now());
        }
        $this->runs->save($run);
    }

    private function close(?TargetConnection $target): void
    {
        try {
            $target?->close();
        } catch (\Throwable) {
            // La connexion est perdue ou déjà fermée : rien à ajouter.
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
