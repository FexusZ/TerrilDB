<?php

declare(strict_types=1);

namespace App\ScriptRun;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditLog;
use App\Audit\AuditOutcome;
use App\Connection\TargetOpener;
use App\Core\Query\Script\ScriptService;
use App\Core\Query\WritePermissions;
use App\Crypto\DerivedKey;
use App\Entity\ScriptRun;
use App\Entity\ScriptRunStatus;
use App\Entity\User;
use App\Repository\ScriptRunRepository;
use App\ScriptRun\Channel\ScriptWorkerBusyException;
use App\ScriptRun\Channel\ScriptWorkerUnavailableException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lance une exécution de script détachée (#169) : juge le script comme la console (mêmes refus, une confirmation, jeu à blanc), prépare la
 * cible (identifiants déchiffrés, adresse contrôlée), note l'exécution `queued` et la ligne d'audit « script démarré », puis la confie au
 * worker. Si le worker ne répond pas, l'exécution est terminée `failed` : rien ne reste en file pour toujours.
 */
final readonly class ScriptRunStarter
{
    public function __construct(
        private TargetOpener $targets,
        private ScriptService $scripts,
        private ScriptRunRepository $runs,
        private ScriptRunLauncher $launcher,
        private AuditLog $audit,
        private ClockInterface $clock,
        private ScriptRunTimeLimit $limit,
    ) {
    }

    /**
     * @throws \App\Connection\Exception\ConnectionNotFoundException
     * @throws \App\Connection\Exception\ForbiddenTargetHostException
     * @throws \App\Core\Driver\Exception\UnsupportedOperationException          connexion avec rebond ou moteur sans SQL
     * @throws \App\Core\Query\Exception\QueryRefusedException                   script vide
     * @throws \App\Core\Query\Script\Exception\ScriptRefusedException
     * @throws \App\Core\Query\Script\Exception\ScriptNeedsConfirmationException
     * @throws \App\Core\Query\Script\Exception\ScriptDryRunUnavailableException
     * @throws \App\Core\Query\Exception\InvalidQueryOptionsException            délai hors bornes (`ScriptRunTimeLimit`)
     * @throws Exception\InvalidScriptRunRequestException
     * @throws ScriptWorkerUnavailableException
     * @throws ScriptWorkerBusyException
     */
    public function start(User $owner, DerivedKey $key, AuditContext $context, Uuid $connectionId, string $script, bool $confirmed, bool $dryRun, int $timeoutMs, ?string $database, ?string $schema, bool $lenient = false, ?WritePermissions $writes = null, ?Uuid $apiTokenId = null): ScriptRun
    {
        $this->limit->check($timeoutMs);
        $target = $this->targets->resolve($owner, $key, $connectionId, $database, $schema);
        $writes ??= WritePermissions::all();
        $report = $this->scripts->judge($script, $target->engine, $writes, $dryRun, $confirmed, $lenient);

        $id = Uuid::v7();
        // Construit AVANT d'écrire quoi que ce soit : un message refusé (délai hors bornes, script trop gros) ne laisse aucune exécution.
        $request = new ScriptRunRequest($id, $target->engine, $target->parameters, $script, $writes, $confirmed, $dryRun, $timeoutMs, $context, $lenient);
        $textHash = hash('sha256', $script);

        $run = new ScriptRun($id, $owner, $connectionId, $textHash, \count($report->steps), $dryRun, $this->now(), $apiTokenId);
        $this->runs->save($run);
        $this->audit->record($context, AuditAction::ScriptStarted, AuditOutcome::Success, $connectionId, [
            'executionId' => $id->toRfc4122(),
            'engine' => $target->engine->value,
            'statements' => \count($report->steps),
            'dryRun' => $dryRun,
            'confirmed' => $confirmed,
            'textHash' => $textHash,
        ]);

        try {
            $this->launcher->dispatch($request);
        } catch (ScriptWorkerUnavailableException|ScriptWorkerBusyException $e) {
            $run->finish(ScriptRunStatus::Failed, $this->now());
            $this->runs->save($run);

            throw $e;
        }

        return $run;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
