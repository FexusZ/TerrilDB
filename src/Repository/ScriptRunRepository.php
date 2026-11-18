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
