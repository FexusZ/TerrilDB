<?php

declare(strict_types=1);

namespace App\Crypto;

use App\Crypto\Exception\DecryptionFailedException;
use App\Crypto\Exception\MalformedPayloadException;
use App\Crypto\Exception\UnsupportedFormatVersionException;

/**
 * Chiffrement authentifié (XChaCha20-Poly1305) des identifiants de connexion.
 *
 * Format v1 : "v1:" + base64url(nonce[24] || chiffré+tag). Un nonce aléatoire neuf à chaque appel ;
 * le contexte (ex. l'identifiant de la connexion) est lié au chiffré comme donnée authentifiée, de
 * sorte qu'un chiffré recopié sur une autre ligne ne se déchiffre pas.
 */
final readonly class SecretCipher
{
    private const VERSION = 1;
    private const PREFIX = 'v1:';

    public function encrypt(#[\SensitiveParameter] string $plaintext, DerivedKey $key, string $context = ''): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $this->associatedData(self::VERSION, $context),
            $nonce,
            $key->bytes(),
        );

        return self::PREFIX.sodium_bin2base64($nonce.$ciphertext, \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function decrypt(string $blob, DerivedKey $key, string $context = ''): string
    {
        if (1 !== preg_match('/^v(\d+):(.*)$/s', $blob, $matches)) {
            throw new MalformedPayloadException('The encrypted payload has no version prefix.');
        }

        $version = (int) $matches[1];
        if (self::VERSION !== $version) {
            throw UnsupportedFormatVersionException::for('encrypted payload', $version);
        }

        try {
            $raw = sodium_base642bin($matches[2], \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw new MalformedPayloadException('The encrypted payload is not valid base64.');
        }

        $nonceLength = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (\strlen($raw) < $nonceLength + \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new MalformedPayloadException('The encrypted payload is too short.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, $nonceLength),
            $this->associatedData($version, $context),
            substr($raw, 0, $nonceLength),
            $key->bytes(),
        );

        // Mauvaise clé et donnée altérée sont volontairement indiscernables.
        return false === $plaintext
            ? throw new DecryptionFailedException('The payload could not be decrypted.') : $plaintext;
    }

    private function associatedData(int $version, string $context): string
    {
        return pack('C', $version).$context;
    }
}
