<?php

declare(strict_types=1);

namespace App\Confirmation;

use App\Confirmation\Exception\InvalidConfirmationException;
use App\Entity\ApiToken;
use App\Entity\ConfirmationTicket;
use App\Repository\ConfirmationTicketRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Les jetons de confirmation d'une écriture préparée (`prepare`, puis `execute` avec le jeton reçu) : liés au jeton d'API,
 * à la connexion, au texte EXACT et au verdict ; valables peu de temps ; consommés à la première présentation, qu'elle
 * réussisse ou non (un jeton qui a servi à autre chose que ce pour quoi il a été émis est brûlé).
 */
final readonly class ConfirmationTickets
{
    public const LIFETIME_SECONDS = 300;
    private const PREFIX = 'cft_';

    public function __construct(
        private ConfirmationTicketRepository $tickets,
        private ClockInterface $clock,
    ) {
    }

    public function issue(ApiToken $token, ConfirmationBinding $binding): IssuedConfirmation
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $expires = $now->modify(\sprintf('+%d seconds', self::LIFETIME_SECONDS));
        $plain = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->tickets->purgeExpired($token->getId(), $now);
        $this->tickets->save(new ConfirmationTicket(
            Uuid::v7(),
            $token,
            self::hash($plain),
            $binding->connectionId,
            $binding->kind->value,
            $binding->textHash(),
            $binding->decision,
            $now->setTimezone(new \DateTimeZone('UTC')),
            $expires->setTimezone(new \DateTimeZone('UTC')),
        ));

        return new IssuedConfirmation($plain, $expires);
    }

    /**
     * @throws InvalidConfirmationException
     */
    public function redeem(#[\SensitiveParameter] string $presented, ConfirmationBinding $binding): void
    {
        $row = $this->tickets->consume(self::hash($presented));
        if (null === $row) {
            throw InvalidConfirmationException::create();
        }

        $expiresAt = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $matches = Uuid::fromString($row['api_token_id'])->equals($binding->apiTokenId)
            && Uuid::fromString($row['connection_id'])->equals($binding->connectionId)
            && $row['kind'] === $binding->kind->value
            && hash_equals($row['text_hash'], $binding->textHash())
            && $row['decision'] === $binding->decision;
        if (!$matches || $now >= $expiresAt) {
            throw InvalidConfirmationException::create();
        }
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
