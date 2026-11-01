<?php

declare(strict_types=1);

namespace App\Entity;

use App\Audit\AuditAction;
use App\Audit\AuditActor;
use App\Audit\AuditOutcome;
use App\Audit\Exception\InvalidAuditEventException;
use App\Repository\AuditEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une ligne du journal d'audit. Immuable : on ajoute, on ne modifie jamais. Le compte n'est pas une clé étrangère :
 * le journal d'un compte supprimé reste lisible par l'administrateur de l'installation. Aucun secret n'y entre : les
 * détails n'acceptent que des valeurs simples, sous des clés qui ne ressemblent pas à un mot de passe, un jeton ou un code.
 */
#[ORM\Entity(repositoryClass: AuditEventRepository::class)]
#[ORM\Table(name: 'audit_event')]
#[ORM\Index(name: 'idx_audit_user_time', columns: ['user_id', 'occurred_at'])]
final class AuditEvent
{
    public const MAX_TEXT_LENGTH = 10_000;
    private const MAX_DETAILS = 20;
    private const MAX_LIST_ITEMS = 50;
    private const MAX_LIST_ITEM_LENGTH = 200;
    private const IP_MAX_LENGTH = 45;
    private const KEY_PATTERN = '/^[a-z][a-zA-Z0-9_]{0,39}$/D';
    private const FORBIDDEN_KEYS = ['password', 'passwd', 'secret', 'token', 'code', 'key'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $userId;

    #[ORM\Column(length: 8, enumType: AuditActor::class)]
    private AuditActor $actor;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $tokenId;

    #[ORM\Column(length: self::IP_MAX_LENGTH, nullable: true)]
    private ?string $ip;

    #[ORM\Column(length: 40, enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(length: 8, enumType: AuditOutcome::class)]
    private AuditOutcome $outcome;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $subjectId;

    /** @var array<string, bool|float|int|list<string>|string|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        Uuid $id,
        \DateTimeImmutable $occurredAt,
        ?Uuid $userId,
        AuditActor $actor,
        ?Uuid $tokenId,
        ?string $ip,
        AuditAction $action,
        AuditOutcome $outcome,
        ?Uuid $subjectId,
        array $details,
    ) {
        $this->id = $id;
        $this->occurredAt = $occurredAt->setTimezone(new \DateTimeZone('UTC'));
        $this->userId = $userId;
        $this->actor = $actor;
        $this->tokenId = $tokenId;
        $this->ip = null === $ip ? null : substr($ip, 0, self::IP_MAX_LENGTH);
        $this->action = $action;
        $this->outcome = $outcome;
        $this->subjectId = $subjectId;
        $this->details = self::normalize($details);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function getActor(): AuditActor
    {
        return $this->actor;
    }

    public function getTokenId(): ?Uuid
    {
        return $this->tokenId;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getOutcome(): AuditOutcome
    {
        return $this->outcome;
    }

    public function getSubjectId(): ?Uuid
    {
        return $this->subjectId;
    }

    /**
     * @return array<string, bool|float|int|list<string>|string|null>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, bool|float|int|list<string>|string|null>
     */
    private static function normalize(array $details): array
    {
        if (\count($details) > self::MAX_DETAILS) {
            throw new InvalidAuditEventException('An audit event carries at most 20 details.');
        }

        $normalized = [];
        foreach ($details as $key => $value) {
            if (1 !== preg_match(self::KEY_PATTERN, (string) $key)
                || \in_array(strtolower((string) $key), self::FORBIDDEN_KEYS, true)
                || str_contains(strtolower((string) $key), 'password')
                || str_contains(strtolower((string) $key), 'secret')) {
                throw new InvalidAuditEventException(\sprintf('"%s" cannot be an audit detail name.', (string) $key));
            }
            $normalized[(string) $key] = self::value($value);
        }

        return $normalized;
    }

    /**
     * @return bool|float|int|list<string>|string|null
     */
    private static function value(mixed $value): bool|float|int|array|string|null
    {
        if (null === $value || \is_bool($value) || \is_int($value) || \is_float($value)) {
            return $value;
        }
        if (\is_string($value)) {
            return mb_substr($value, 0, self::MAX_TEXT_LENGTH);
        }
        if (\is_array($value) && array_is_list($value) && \count($value) <= self::MAX_LIST_ITEMS) {
            $items = [];
            foreach ($value as $item) {
                if (!\is_string($item)) {
                    throw new InvalidAuditEventException('An audit detail list holds strings only.');
                }
                $items[] = mb_substr($item, 0, self::MAX_LIST_ITEM_LENGTH);
            }

            return $items;
        }

        throw new InvalidAuditEventException('An audit detail must be a scalar or a short list of strings.');
    }
}
