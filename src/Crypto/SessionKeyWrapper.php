<?php

declare(strict_types=1);

namespace App\Crypto;

use App\Crypto\Exception\InvalidKeyException;

/**
 * Enveloppe la clé de session avant de la poser dans le stockage serveur. Le secret de déverrouillage,
 * aléatoire par session, ne quitte jamais le navigateur (cookie dédié) : le stockage seul ne suffit pas
 * à retrouver la clé. L'identifiant de session est lié à l'enveloppe comme contexte authentifié.
 */
final readonly class SessionKeyWrapper
{
    public function __construct(private SecretCipher $cipher = new SecretCipher())
    {
    }

    public function generateUnlockSecret(): string
    {
        return sodium_bin2base64(random_bytes(DerivedKey::LENGTH), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function wrap(DerivedKey $key, #[\SensitiveParameter] string $unlockSecret, string $sessionId): string
    {
        $unlockKey = $this->unlockKey($unlockSecret);

        try {
            return $this->cipher->encrypt($key->bytes(), $unlockKey, $sessionId);
        } finally {
            $unlockKey->wipe();
        }
    }

    public function unwrap(string $wrapped, #[\SensitiveParameter] string $unlockSecret, string $sessionId): DerivedKey
    {
        $unlockKey = $this->unlockKey($unlockSecret);

        try {
            return new DerivedKey($this->cipher->decrypt($wrapped, $unlockKey, $sessionId));
        } finally {
            $unlockKey->wipe();
        }
    }

    private function unlockKey(#[\SensitiveParameter] string $unlockSecret): DerivedKey
    {
        try {
            $bytes = sodium_base642bin($unlockSecret, \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw new InvalidKeyException('The unlock secret is not valid base64.');
        }

        return new DerivedKey($bytes);
    }
}
