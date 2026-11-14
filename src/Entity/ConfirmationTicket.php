<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConfirmationTicketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le jeton de confirmation d'une écriture préparée, à usage unique. Le serveur ne garde que l'empreinte du jeton et celle
 * du texte (jamais l'instruction elle-même) : le texte à exécuter est celui que le client renvoie, comparé à l'empreinte.
 */
#[ORM\Entity(repositoryClass: ConfirmationTicketRepository::class)]
#[ORM\Table(name: 'confirmation_ticket')]
#[ORM\Index(name: 'idx_confirmation_token_expiry', columns: ['api_token_id', 'expires_at'])]
final class ConfirmationTicket
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ApiToken $apiToken;

    #[ORM\Column(length: 64, unique: true)]
    private string $ticketHash;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $connectionId;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 64)]
    private string $textHash;

    #[ORM\Column(length: 24)]
    private string $decision;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    public function __construct(Uuid $id, ApiToken $apiToken, string $ticketHash, Uuid $connectionId, string $kind, string $textHash, string $decision, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt)
    {
        $this->id = $id;
        $this->apiToken = $apiToken;
        $this->ticketHash = $ticketHash;
        $this->connectionId = $connectionId;
        $this->kind = $kind;
        $this->textHash = $textHash;
        $this->decision = $decision;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }
}
