<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Codes de récupération du second facteur, à usage unique. Ils ne remplacent que le code TOTP : le
 * mot de passe reste requis, et il n'ouvre pas la clé de chiffrement.
 *
 * Chaque code porte 80 bits d'entropie (16 caractères Crockford) : un hash rapide suffit, contrairement
 * à un mot de passe choisi par l'humain.
 */
final readonly class RecoveryCodeService
{
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';
    private const GROUPS = 4;
    private const GROUP_LENGTH = 4;
    private const DEFAULT_COUNT = 10;

    public function generate(int $count = self::DEFAULT_COUNT): NewRecoveryCodes
    {
        $codes = [];
        $hashes = [];
        while (\count($codes) < $count) {
            $code = $this->randomCode();
            if (\in_array($code, $codes, true)) {
                continue;
            }

            $codes[] = $code;
            $hashes[] = $this->hash($code);
        }

        return new NewRecoveryCodes($codes, $hashes);
    }

    public function hash(#[\SensitiveParameter] string $code): string
    {
        return hash('sha256', $this->normalize($code));
    }

    /**
     * @param list<string> $hashes
     *
     * @return list<string>|null les hash restants sans celui du code utilisé, ou null si le code est inconnu
     */
    public function consume(#[\SensitiveParameter] string $code, array $hashes): ?array
    {
        if (1 !== preg_match('/^['.self::ALPHABET.']{'.(self::GROUPS * self::GROUP_LENGTH).'}$/', $this->normalize($code))) {
            return null;
        }

        $candidate = $this->hash($code);
        $remaining = [];
        $found = false;
        // Tous les hash sont comparés, sans court-circuit.
        foreach ($hashes as $hash) {
            if (!$found && hash_equals($hash, $candidate)) {
                $found = true;

                continue;
            }
            $remaining[] = $hash;
        }

        return $found ? $remaining : null;
    }

    private function randomCode(): string
    {
        $groups = [];
        for ($group = 0; $group < self::GROUPS; ++$group) {
            $chars = '';
            for ($index = 0; $index < self::GROUP_LENGTH; ++$index) {
                $chars .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $chars;
        }

        return implode('-', $groups);
    }

    private function normalize(#[\SensitiveParameter] string $code): string
    {
        return strtr(strtolower(str_replace([' ', '-'], '', $code)), ['o' => '0', 'i' => '1', 'l' => '1']);
    }
}
