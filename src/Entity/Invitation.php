<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\InvalidInvitationException;
use App\Repository\InvitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Invitation à créer un compte, émise par un administrateur. Le secret n'est pas ici : on ne garde que son empreinte,
 * si bien qu'une fuite de la base ne permet pas de s'inscrire. Elle sert une seule fois et expire.
 * La note (« Pour Marie ») n'est qu'un repère pour l'administrateur : jamais d'adresse e-mail, le serveur n'en envoie pas.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitation')]
final class Invitation
{
    private const NOTE_MAX_LENGTH = 100;
    private const VERIFIER_PATTERN = '/^[0-9a-f]{64}$/D';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column(length: self::NOTE_MAX_LENGTH)]
    private string $note;

    #[ORM\Column(length: 64)]
    private string $verifier;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $usedBy = null;

    // Deux inscriptions simultanées avec la même invitation : la seconde écriture échoue au lieu de passer.
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    public function __construct(
        Uuid $id,
        User $createdBy,
        string $note,
        string $verifier,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ) {
        $note = trim($note);
        if (mb_strlen($note) > self::NOTE_MAX_LENGTH || 1 === preg_match('/\p{Cc}/u', $note)) {
            throw new InvalidInvitationException('The invitation note must be at most 100 characters, without control characters.');
        }
        if (1 !== preg_match(self::VERIFIER_PATTERN, $verifier)) {
            throw new InvalidInvitationException('The invitation verifier is not valid.');
        }
        $utc = new \DateTimeZone('UTC');
        $createdAt = $createdAt->setTimezone($utc);
        $expiresAt = $expiresAt->setTimezone($utc);
        if ($expiresAt <= $createdAt) {
            throw new InvalidInvitationException('The invitation must expire after its creation.');
        }

        $this->id = $id;
        $this->createdBy = $createdBy;
        $this->note = $note;
        $this->verifier = $verifier;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function wasCreatedBy(User $user): bool
    {
        return $this->createdBy->getId()->equals($user->getId());
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getUsedBy(): ?User
    {
        return $this->usedBy;
    }

    public function matches(#[\SensitiveParameter] string $verifier): bool
    {
        return hash_equals($this->verifier, $verifier);
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && !$this->isExpired($now);
    }

    public function markUsed(User $by, \DateTimeImmutable $now): void
    {
        if (!$this->isUsable($now)) {
            throw new InvalidInvitationException('The invitation cannot be used any more.');
        }

        $this->usedBy = $by;
        $this->usedAt = $now->setTimezone(new \DateTimeZone('UTC'));
    }
}
