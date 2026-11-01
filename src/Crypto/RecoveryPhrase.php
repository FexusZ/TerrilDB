<?php

declare(strict_types=1);

namespace App\Crypto;

use App\Crypto\Exception\InvalidRecoveryPhraseException;

/**
 * Phrase de récupération : 160 bits aléatoires écrits en base 32 de Crockford (sans I, L, O, U), huit groupes de
 * quatre caractères. Elle est montrée une fois à l'utilisateur, qui la garde hors ligne ; le serveur n'en garde rien.
 * Aléatoire et longue, elle n'a pas besoin d'une dérivation lente : un hachage à clé de domaine suffit à en tirer le
 * secret qui enveloppe la clé de chiffrement (SessionKeyWrapper).
 */
final readonly class RecoveryPhrase
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const LENGTH = 32;
    private const BYTES = 20;
    private const GROUP = 4;

    private function __construct(#[\SensitiveParameter] private string $normalized)
    {
    }

    public static function generate(): self
    {
        $bytes = random_bytes(self::BYTES);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }
        $text = '';
        foreach (str_split($bits, 5) as $chunk) {
            $text .= self::ALPHABET[(int) bindec($chunk)];
        }
        sodium_memzero($bytes);

        return new self($text);
    }

    /**
     * Lit ce que l'utilisateur a retapé : casse, espaces et tirets sont indifférents, et O, I, L sont lus comme 0, 1, 1.
     */
    public static function parse(#[\SensitiveParameter] string $typed): self
    {
        $text = strtr(strtoupper((string) preg_replace('/[\s-]+/', '', $typed)), ['O' => '0', 'I' => '1', 'L' => '1']);
        if (self::LENGTH !== \strlen($text) || \strlen($text) !== strspn($text, self::ALPHABET)) {
            throw new InvalidRecoveryPhraseException('The recovery phrase is not valid.');
        }

        return new self($text);
    }

    public function toString(): string
    {
        return implode('-', str_split($this->normalized, self::GROUP));
    }

    /**
     * Le secret qui enveloppe la clé : même forme que le secret de déverrouillage de session (base64 URL, 32 octets).
     */
    public function unlockSecret(): string
    {
        return sodium_bin2base64(sodium_crypto_generichash('terrildb-recovery'."\0".$this->normalized), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['phrase' => '***'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('A recovery phrase must not be serialized.');
    }
}
