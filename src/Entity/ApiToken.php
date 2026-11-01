<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\InvalidApiTokenDefinitionException;
use App\Repository\ApiTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un jeton d'accès à l'API en lecture seule. Le secret n'est pas ici : on garde son empreinte (pour reconnaître le
 * jeton) et une copie de la clé de l'utilisateur enveloppée par ce secret. Le jeton n'ouvre que les connexions
 * listées, et seulement jusqu'à son expiration. Un changement de mot de passe le rend inutilisable (la clé
 * enveloppée n'est plus la bonne) : il est supprimé à ce moment-là.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_token')]
final class ApiToken
{
    private const NAME_MAX_LENGTH = 100;
    private const MAX_CONNECTIONS = 50;
    private const WRAPPED_PATTERN = '/^v\d+:[A-Za-z0-9_-]+$/D';
    private const VERIFIER_PATTERN = '/^[0-9a-f]{64}$/D';
    private const USE_RECORD_INTERVAL_SECONDS = 60;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(length: 64)]
    private string $verifier;

    #[ORM\Column(type: Types::TEXT)]
    private string $wrappedKey;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $connectionIds;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    /**
     * @param list<string> $connectionIds identifiants (UUID) des connexions que le jeton peut ouvrir
     */
    public function __construct(
        Uuid $id,
        User $owner,
        string $name,
        string $verifier,
        string $wrappedKey,
        array $connectionIds,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ) {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH || 1 === preg_match('/\p{Cc}/u', $name)) {
            throw new InvalidApiTokenDefinitionException('The token name must be 1 to 100 characters, without control characters.');
        }
        if (1 !== preg_match(self::VERIFIER_PATTERN, $verifier)) {
            throw new InvalidApiTokenDefinitionException('The token verifier is not valid.');
        }
        if (1 !== preg_match(self::WRAPPED_PATTERN, $wrappedKey)) {
            throw new InvalidApiTokenDefinitionException('The wrapped key must be stored encrypted.');
        }
        $utc = new \DateTimeZone('UTC');
        $createdAt = $createdAt->setTimezone($utc);
        $expiresAt = $expiresAt->setTimezone($utc);
        if ($expiresAt <= $createdAt) {
            throw new InvalidApiTokenDefinitionException('The token must expire after its creation.');
        }

        $this->id = $id;
        $this->owner = $owner;
        $this->name = $name;
        $this->verifier = $verifier;
        $this->wrappedKey = $wrappedKey;
        $this->connectionIds = self::normalizeConnections($connectionIds);
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function belongsTo(User $user): bool
    {
        return $this->owner->getId()->equals($user->getId());
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * La clé de l'utilisateur, enveloppée par le secret du jeton (jamais en clair).
     */
    public function getWrappedKey(): string
    {
        return $this->wrappedKey;
    }

    /**
     * @return list<string>
     */
    public function getConnectionIds(): array
    {
        return $this->connectionIds;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function allows(Uuid $connectionId): bool
    {
        return \in_array($connectionId->toRfc4122(), $this->connectionIds, true);
    }

    public function matches(#[\SensitiveParameter] string $verifier): bool
    {
        return hash_equals($this->verifier, $verifier);
    }

    /**
     * Mémorise la dernière utilisation, au plus une fois par minute : une écriture à chaque requête serait inutile.
     *
     * @return bool vrai si la valeur a changé (l'appelant doit alors enregistrer)
     */
    public function recordUse(\DateTimeImmutable $now): bool
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        if (null !== $this->lastUsedAt && $now->getTimestamp() - $this->lastUsedAt->getTimestamp() < self::USE_RECORD_INTERVAL_SECONDS) {
            return false;
        }

        $this->lastUsedAt = $now;

        return true;
    }

    /**
     * @param array<array-key, mixed> $connectionIds
     *
     * @return list<string>
     */
    private static function normalizeConnections(array $connectionIds): array
    {
        $normalized = [];
        foreach ($connectionIds as $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                throw new InvalidApiTokenDefinitionException('A token connection must be identified by a UUID.');
            }
            $normalized[Uuid::fromString($id)->toRfc4122()] = true;
        }
        if (\count($normalized) > self::MAX_CONNECTIONS) {
            throw new InvalidApiTokenDefinitionException('A token can open at most 50 connections.');
        }

        return array_keys($normalized);
    }
}
