<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\Exception\PasswordPolicyException;

/**
 * Règle unique sur les mots de passe choisis par les utilisateurs : longueur (en caractères, pas en
 * octets) plutôt que composition. Le maximum est celui que le hasher accepte.
 */
final readonly class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 4096;

    public function assertAcceptable(#[\SensitiveParameter] string $password): void
    {
        if (!mb_check_encoding($password, 'UTF-8')) {
            throw PasswordPolicyException::invalidEncoding();
        }

        $length = mb_strlen($password, 'UTF-8');
        if ($length < self::MIN_LENGTH) {
            throw PasswordPolicyException::tooShort(self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            throw PasswordPolicyException::tooLong(self::MAX_LENGTH);
        }
        if (1 === preg_match('/^\s*$/u', $password)) {
            throw PasswordPolicyException::blank();
        }
    }
}
