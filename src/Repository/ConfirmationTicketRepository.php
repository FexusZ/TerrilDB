<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConfirmationTicket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ConfirmationTicket>
 */
final class ConfirmationTicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfirmationTicket::class);
    }

    public function save(ConfirmationTicket $ticket): void
    {
        $this->getEntityManager()->persist($ticket);
        $this->getEntityManager()->flush();
    }

    /**
     * Retire (et rend) le jeton présenté en UNE instruction atomique : deux présentations simultanées ne peuvent pas
     * toutes deux le trouver. Le jeton est consommé même si ce qui est présenté ne correspond pas.
     *
     * @return array{api_token_id: string, connection_id: string, kind: string, text_hash: string, decision: string, expires_at: string}|null
     */
    public function consume(string $ticketHash): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'DELETE FROM confirmation_ticket WHERE ticket_hash = ? RETURNING api_token_id, connection_id, kind, text_hash, decision, expires_at',
            [$ticketHash],
        );
        if (false === $row) {
            return null;
        }

        return [
            'api_token_id' => self::text($row['api_token_id'] ?? null),
            'connection_id' => self::text($row['connection_id'] ?? null),
            'kind' => self::text($row['kind'] ?? null),
            'text_hash' => self::text($row['text_hash'] ?? null),
            'decision' => self::text($row['decision'] ?? null),
            'expires_at' => self::text($row['expires_at'] ?? null),
        ];
    }

    public function purgeExpired(Uuid $apiTokenId, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM confirmation_ticket WHERE api_token_id = ? AND expires_at <= ?',
            [$apiTokenId->toRfc4122(), $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
