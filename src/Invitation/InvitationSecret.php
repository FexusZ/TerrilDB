<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Invitation\Exception\InvalidInvitationTokenException;
use Symfony\Component\Uid\Uuid;

/**
 * Une invitation telle que l'invité la reçoit : `inv_<identifiant>_<secret>`. L'identifiant (un UUID sans tirets)
 * retrouve la ligne ; le secret, 256 bits aléatoires, n'est jamais stocké : le serveur n'en garde que l'empreinte.
 */
final readonly class InvitationSecret
{
    private const PATTERN = '/^inv_([0-9a-f]{32})_([A-Za-z0-9_-]{43})$/D';

    private function __construct(
        public Uuid $id,
        #[\SensitiveParameter] private string $secret,
    ) {
    }

    public static function generate(Uuid $id): self
    {
        return new self($id, sodium_bin2base64(random_bytes(32), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING));
    }

    public static function parse(#[\SensitiveParameter] string $token): self
    {
        if (1 !== preg_match(self::PATTERN, $token, $parts)) {
            throw InvalidInvitationTokenException::create();
        }

        $hex = $parts[1];
        $uuid = \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
        if (!Uuid::isValid($uuid)) {
            throw InvalidInvitationTokenException::create();
        }

        return new self(Uuid::fromString($uuid), $parts[2]);
    }

    public function toString(): string
    {
        return \sprintf('inv_%s_%s', str_replace('-', '', $this->id->toRfc4122()), $this->secret);
    }

    /**
     * Empreinte qui permet de reconnaître l'invitation sans pouvoir la reconstituer.
     */
    public function verifier(): string
    {
        return hash('sha256', 'terrildb-invitation'."\0".$this->secret);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id->toRfc4122(), 'secret' => '***'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('An invitation must not be serialized.');
    }
}
