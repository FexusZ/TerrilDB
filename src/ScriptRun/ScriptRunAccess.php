<?php

declare(strict_types=1);

namespace App\ScriptRun;

use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Entity\ScriptRun;
use App\Entity\User;
use App\Repository\ScriptRunRepository;
use App\ScriptRun\Exception\ScriptRunFinishedException;
use App\ScriptRun\Exception\ScriptRunNotFoundException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lire et annuler une exécution, toujours cloisonné par compte (celle d'un autre est indiscernable d'une inexistante).
 */
final readonly class ScriptRunAccess
{
    public const int DEFAULT_LIST = 20;
    public const int MAX_LIST = 100;

    public function __construct(private ScriptRunRepository $runs, private ClockInterface $clock)
    {
    }

    /**
     * Les exécutions vivantes sans signe de vie sont déclarées interrompues À LA LECTURE : le suivi ne ment pas, même si le worker est mort.
     *
     * @throws ScriptRunNotFoundException
     */
    public function read(User $owner, Uuid $id): ScriptRun
    {
        $this->runs->interruptStale($this->now());

        return $this->owned($owner, $id);
    }

    /**
     * Les exécutions du compte, les plus récentes d'abord ; une exécution vivante sans signe de vie y est déjà déclarée interrompue.
     *
     * @return list<ScriptRun>
     */
    public function list(User $owner, int $limit, ?Uuid $connection): array
    {
        if ($limit < 1 || $limit > self::MAX_LIST) {
            throw new InvalidQueryOptionsException(\sprintf('limit must be between 1 and %d.', self::MAX_LIST));
        }
        $this->runs->interruptStale($this->now());

        return $this->runs->findOwnedBy($owner->getId(), $limit, $connection);
    }

    /**
     * Demande l'arrêt entre deux instructions (jamais l'interruption d'une instruction en cours). Répéter la demande ne change rien.
     *
     * @throws ScriptRunNotFoundException
     * @throws ScriptRunFinishedException l'exécution est déjà terminée
     */
    public function cancel(User $owner, Uuid $id): ScriptRun
    {
        $this->runs->interruptStale($this->now());
        $run = $this->owned($owner, $id);
        if ($run->getStatus()->isTerminal()) {
            throw new ScriptRunFinishedException('The run is finished.');
        }
        $this->runs->requestCancelOf($id);
        // La demande est écrite en SQL : l'objet en mémoire est relu pour la rendre.
        $this->runs->refresh($run);

        return $run;
    }

    private function owned(User $owner, Uuid $id): ScriptRun
    {
        $run = $this->runs->findOwned($id, $owner->getId());
        if (null === $run) {
            throw new ScriptRunNotFoundException('No such run.');
        }
        // Une ligne modifiée par le worker depuis la dernière lecture de cette requête : on relit l'état réel.
        $this->runs->refresh($run);

        return $run;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
