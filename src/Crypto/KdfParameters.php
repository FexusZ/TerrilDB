<?php

declare(strict_types=1);

namespace App\Crypto;

use App\Crypto\Exception\InvalidKdfParametersException;
use App\Crypto\Exception\UnsupportedFormatVersionException;

/**
 * Paramètres Argon2id d'une dérivation de clé, stockables en base à côté de l'utilisateur.
 * Ils sont versionnés pour pouvoir changer d'algorithme ou de coût sans casser les comptes existants.
 */
final readonly class KdfParameters
{
    public const CURRENT_VERSION = 1;
    public const SALT_BYTES = \SODIUM_CRYPTO_PWHASH_SALTBYTES;
    public const MIN_OPS_LIMIT = 1;
    public const MIN_MEM_LIMIT = 8192;
    // Bornes hautes : des valeurs lues en base ne doivent pas pouvoir épuiser le serveur.
    public const MAX_OPS_LIMIT = 16;
    public const MAX_MEM_LIMIT = 2 * 1024 * 1024 * 1024;

    public int $version;

    public function __construct(
        public int $opsLimit,
        public int $memLimit,
        public string $salt,
    ) {
        if ($opsLimit < self::MIN_OPS_LIMIT || $opsLimit > self::MAX_OPS_LIMIT) {
            throw new InvalidKdfParametersException('Argon2id ops limit is out of bounds.');
        }
        if ($memLimit < self::MIN_MEM_LIMIT || $memLimit > self::MAX_MEM_LIMIT) {
            throw new InvalidKdfParametersException('Argon2id memory limit is out of bounds.');
        }
        if (self::SALT_BYTES !== \strlen($salt)) {
            throw new InvalidKdfParametersException('Argon2id salt has an invalid length.');
        }

        $this->version = self::CURRENT_VERSION;
    }

    public function toString(): string
    {
        return \sprintf(
            '%d$%d$%d$%s',
            $this->version,
            $this->opsLimit,
            $this->memLimit,
            sodium_bin2base64($this->salt, \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
        );
    }

    public static function fromString(string $value): self
    {
        $parts = explode('$', $value);
        if (4 !== \count($parts)) {
            throw new InvalidKdfParametersException('Malformed KDF parameters.');
        }

        [$version, $opsLimit, $memLimit, $encodedSalt] = $parts;
        if (!ctype_digit($version) || !ctype_digit($opsLimit) || !ctype_digit($memLimit)) {
            throw new InvalidKdfParametersException('Malformed KDF parameters.');
        }
        if (self::CURRENT_VERSION !== (int) $version) {
            throw UnsupportedFormatVersionException::for('KDF parameters', (int) $version);
        }

        try {
            $salt = sodium_base642bin($encodedSalt, \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw new InvalidKdfParametersException('Malformed KDF salt.');
        }

        return new self((int) $opsLimit, (int) $memLimit, $salt);
    }
}
