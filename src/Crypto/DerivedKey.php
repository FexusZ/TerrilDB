<?php

declare(strict_types=1);

namespace App\Crypto;

use App\Crypto\Exception\InvalidKeyException;
use App\Crypto\Exception\KeyWipedException;

/**
 * Clé symétrique dérivée du mot de passe de l'utilisateur. Elle ne vit qu'en mémoire : elle s'efface
 * explicitement (wipe) ou à la destruction de l'objet, et refuse la sérialisation.
 *
 * Les octets sont capturés par des closures plutôt que stockés dans une propriété (var_export ne les
 * voit pas) et __debugInfo masque les dumps de débogage (print_r, var_dump).
 */
final class DerivedKey
{
    public const LENGTH = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    /** @var \Closure(): ?string */
    private \Closure $read;

    /** @var \Closure(): void */
    private \Closure $erase;

    public function __construct(#[\SensitiveParameter] string $bytes)
    {
        if (self::LENGTH !== \strlen($bytes)) {
            throw new InvalidKeyException('A derived key must be exactly '.self::LENGTH.' bytes long.');
        }

        // PHPStan ne voit pas que les closures remettent $secret à null (capture par référence).
        /** @var string|null $secret */
        $secret = $bytes; // @phpstan-ignore varTag.nativeType
        $this->read = static function () use (&$secret): ?string {
            return $secret;
        };
        $this->erase = static function () use (&$secret): void {
            if (null !== $secret) {
                sodium_memzero($secret);
            }
            $secret = null;
        };
    }

    public function __destruct()
    {
        $this->wipe();
    }

    public function bytes(): string
    {
        return ($this->read)() ?? throw new KeyWipedException('This key has been wiped.');
    }

    public function isWiped(): bool
    {
        return null === ($this->read)();
    }

    public function wipe(): void
    {
        ($this->erase)();
    }

    /**
     * @return array<never, never>
     */
    public function __debugInfo(): array
    {
        return [];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('A derived key must never be serialized.');
    }
}
