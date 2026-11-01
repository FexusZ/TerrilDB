<?php

declare(strict_types=1);

namespace App\Audit;

use Symfony\Component\Uid\Uuid;

/**
 * Qui agit : par quelle porte, pour quel compte, avec quel jeton, depuis quelle adresse.
 */
final readonly class AuditContext
{
    public function __construct(
        public AuditActor $actor,
        public ?Uuid $userId,
        public ?Uuid $tokenId,
        public ?string $ip,
    ) {
    }
}
