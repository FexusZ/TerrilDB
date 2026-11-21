<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScriptRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ScriptRun>
 */
final class ScriptRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScriptRun::class);
    }

    /**
     * Relit la ligne : le worker l'écrit depuis un autre processus, l'objet en mémoire de cette requête ne le sait pas.
     */
    public function refresh(ScriptRun $run): void
    {
        $this->getEntityManager()->refresh($run);
    }

    public function save(ScriptRun $run): void
    {
        $this->getEntityManager()->persist($run);
        $this->getEntityManager()->flush();
    }

    /**
     * Une exécution d'un autre compte est indiscernable d'une exécution inexistante.
     */
    public function findOwned(Uuid $id, Uuid $userId): ?ScriptRun
    {
        return $this->findOneBy(['id' => $id, 'user' => $userId]);
    }

    /**
     * Les exécutions d'UN compte, les plus récentes d'abord (index `user_id, created_at`), éventuellement pour une seule connexion.
     *
     * @return list<ScriptRun>
     */
    public function findOwnedBy(Uuid $userId, int $limit, ?Uuid $connectionId): array
    {
        $criteria = ['user' => $userId];
        if (null !== $connectionId) {
            $criteria['connectionId'] = $connectionId;
        }

        return $this->findBy($criteria, ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /**
     * La demande d'annulation, lue à la base à chaque fois (jamais depuis l'objet en mémoire : une autre requête la pose).
     */
    public function isCancelRequested(Uuid $id): bool
    {
        return true === $this->getEntityManager()->getConnection()->fetchOne('SELECT cancel_requested FROM script_run WHERE id = ?', [$id->toRfc4122()]);
    }

    /**
     * Pose la demande d'annulation d'une exécution vivante, en UNE instruction (sans toucher au reste de la ligne).
     *
     * @return bool vrai si une exécution vivante l'a reçue
     */
    public function requestCancelOf(Uuid $id): bool
    {
        return 1 === (int) $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE script_run SET cancel_requested = TRUE WHERE id = ? AND status IN ('queued', 'running')",
            [$id->toRfc4122()],
        );
    }

    /**
     * Le signe de vie de plusieurs exécutions vivantes à la fois (le worker le donne pour ses processus, pendant qu'une longue
     * instruction occupe celui qui joue le script).
     *
     * @param list<Uuid> $ids
     *
     * @return int le nombre d'exécutions vivantes touchées
     */
    public function beatAll(array $ids, \DateTimeImmutable $now): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            \sprintf("UPDATE script_run SET heartbeat_at = ? WHERE status IN ('queued', 'running') AND id IN (%s)", implode(', ', array_fill(0, \count($ids), '?'))),
            [self::utc($now), ...array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids)],
        );
    }

    /**
     * Déclare interrompues, en UNE instruction, les exécutions vivantes sans signe de vie depuis `HEARTBEAT_TIMEOUT_SECONDS`.
     *
     * @return int le nombre d'exécutions déclarées interrompues
     */
    public function interruptStale(\DateTimeImmutable $now): int
    {
        $limit = $now->modify(\sprintf('-%d seconds', ScriptRun::HEARTBEAT_TIMEOUT_SECONDS));

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE script_run SET status = 'interrupted', finished_at = ? WHERE status IN ('queued', 'running') AND heartbeat_at < ?",
            [self::utc($now), self::utc($limit)],
        );
    }

    /**
     * Efface les exécutions TERMINÉES créées avant la limite. Une exécution vivante n'est jamais effacée : si elle est périmée,
     * `interruptStale` la termine d'abord.
     *
     * @return int le nombre d'exécutions supprimées
     */
    public function purgeOlderThan(\DateTimeImmutable $limit): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            "DELETE FROM script_run WHERE created_at < ? AND status NOT IN ('queued', 'running')",
            [self::utc($limit)],
        );
    }

    private static function utc(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
