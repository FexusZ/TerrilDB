<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Connection\Engine;
use App\Entity\Exception\InvalidDatabaseConnectionException;
use App\Repository\DatabaseConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une base cible déclarée par un utilisateur. Le nom et le moteur restent en clair (pour lister), tout le
 * reste (hôte, port, base, identifiant, mot de passe) n'existe ici que chiffré avec la clé de l'utilisateur.
 *
 * L'identifiant est fourni par l'appelant : il sert de contexte authentifié au chiffrement, donc il doit
 * exister avant le chiffré. Le chiffré refuse tout ce qui n'a pas la forme d'un chiffré versionné, pour
 * qu'un oubli de chiffrement ne puisse jamais écrire des paramètres en clair.
 */
#[ORM\Entity(repositoryClass: DatabaseConnectionRepository::class)]
#[ORM\Table(name: 'database_connection')]
#[ORM\UniqueConstraint(name: 'uniq_database_connection_owner_name', columns: ['owner_id', 'name'])]
final class DatabaseConnection
{
    private const NAME_MAX_LENGTH = 100;
    private const ENCRYPTED_PATTERN = '/^v\d+:[A-Za-z0-9_-]+$/D';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(length: 16, enumType: Engine::class)]
    private Engine $engine;

    // Couleur de repère et rang dans la liste du propriétaire : de la mise en forme, rien de secret, donc en clair.
    #[ORM\Column(length: 16, nullable: true, enumType: ConnectionColor::class)]
    private ?ConnectionColor $color = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedParameters;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    public function __construct(
        Uuid $id,
        User $owner,
        string $name,
        Engine $engine,
        string $encryptedParameters,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->owner = $owner;
        $this->name = self::normalizeName($name);
        $this->engine = $engine;
        $this->encryptedParameters = self::assertEncrypted($encryptedParameters);
        $this->createdAt = $createdAt->setTimezone(new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
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

    public function getEngine(): Engine
    {
        return $this->engine;
    }

    /**
     * Les paramètres de connexion chiffrés (jamais en clair).
     */
    public function getEncryptedParameters(): string
    {
        return $this->encryptedParameters;
    }

    public function getColor(): ?ConnectionColor
    {
        return $this->color;
    }

    public function setColor(?ConnectionColor $color): void
    {
        $this->color = $color;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function moveTo(int $position): void
    {
        if ($position < 0) {
            throw new InvalidDatabaseConnectionException('The position cannot be negative.');
        }
        $this->position = $position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(string $name, Engine $engine, string $encryptedParameters, \DateTimeImmutable $now): void
    {
        $name = self::normalizeName($name);
        $encryptedParameters = self::assertEncrypted($encryptedParameters);

        $this->name = $name;
        $this->engine = $engine;
        $this->encryptedParameters = $encryptedParameters;
        $this->updatedAt = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Rechiffrement au changement de mot de passe : le contenu ne change pas, donc pas la date de modification.
     */
    public function rotateEncryptedParameters(string $encryptedParameters): void
    {
        $this->encryptedParameters = self::assertEncrypted($encryptedParameters);
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH || 1 === preg_match('/\p{Cc}/u', $name)) {
            throw new InvalidDatabaseConnectionException('The connection name must be 1 to 100 characters, without control characters.');
        }

        return $name;
    }

    private static function assertEncrypted(string $value): string
    {
        if (1 !== preg_match(self::ENCRYPTED_PATTERN, $value)) {
            throw new InvalidDatabaseConnectionException('The connection parameters must be stored encrypted.');
        }

        return $value;
    }
}
