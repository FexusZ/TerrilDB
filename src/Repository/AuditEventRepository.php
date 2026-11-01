<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Le journal ne s'écrit qu'en ajout. L'insertion passe par DBAL et non par l'unité de travail de l'ORM : un
 * enregistrement d'audit ne doit ni valider, ni dépendre des autres modifications en cours.
 *
 * @extends ServiceEntityRepository<AuditEvent>
 */
final class AuditEventRepository extends ServiceEntityRepository
{
    private const MAX_PAGE = 200;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEvent::class);
    }

    public function append(AuditEvent $event): void
    {
        $this->getEntityManager()->getConnection()->insert('audit_event', [
            'id' => $event->getId()->toRfc4122(),
            'occurred_at' => $event->getOccurredAt()->format('Y-m-d H:i:s'),
            'user_id' => $event->getUserId()?->toRfc4122(),
            'actor' => $event->getActor()->value,
            'token_id' => $event->getTokenId()?->toRfc4122(),
            'ip' => $event->getIp(),
            'action' => $event->getAction()->value,
            'outcome' => $event->getOutcome()->value,
            'subject_id' => $event->getSubjectId()?->toRfc4122(),
            'details' => json_encode($event->getDetails(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        ], [
            'user_id' => ParameterType::STRING,
            'token_id' => ParameterType::STRING,
            'ip' => ParameterType::STRING,
            'subject_id' => ParameterType::STRING,
        ]);
    }

    /**
     * Les événements d'un compte, du plus récent au plus ancien.
     *
     * @param Uuid|null   $before   identifiant du dernier événement déjà vu (pagination)
     * @param string|null $category filtre sur la catégorie de l'action (« query », « login »…)
     * @param Uuid|null   $subject  filtre sur l'objet concerné (une connexion, un jeton…)
     *
     * @return list<AuditEvent>
     */
    public function findForUser(Uuid $user, ?Uuid $before = null, int $limit = 50, ?string $category = null, ?Uuid $subject = null): array
    {
        $builder = $this->createQueryBuilder('e')
            ->where('e.userId = :user')->setParameter('user', $user, 'uuid')
            ->orderBy('e.occurredAt', 'DESC')->addOrderBy('e.id', 'DESC')
            ->setMaxResults(max(1, min($limit, self::MAX_PAGE)));

        if (null !== $category) {
            $builder->andWhere('e.action LIKE :category')->setParameter('category', addcslashes($category, '%_\\').'.%');
        }
        if (null !== $subject) {
            $builder->andWhere('e.subjectId = :subject')->setParameter('subject', $subject, 'uuid');
        }
        if (null !== $before) {
            $cursor = $this->find($before);
            if (null !== $cursor) {
                $builder
                    ->andWhere('(e.occurredAt < :at OR (e.occurredAt = :at AND e.id < :cursor))')
                    ->setParameter('at', $cursor->getOccurredAt(), 'datetime_immutable')
                    ->setParameter('cursor', $before, 'uuid');
            }
        }

        /** @var list<AuditEvent> $events */
        $events = $builder->getQuery()->getResult();

        return $events;
    }

    /**
     * @return int le nombre d'événements supprimés
     */
    public function purgeOlderThan(\DateTimeImmutable $limit): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM audit_event WHERE occurred_at < ?',
            [$limit->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
    }
}
