<?php

declare(strict_types=1);

namespace App\Security;

use OTPHP\TOTP;

/**
 * Mots de passe à usage unique temporels (RFC 6238 : SHA-1, 6 chiffres, période de 30 s), compatibles
 * avec toutes les applications d'authentification courantes.
 *
 * verify() renvoie la période acceptée plutôt qu'un booléen : l'appelant la conserve et la repasse en
 * $lastUsedStep pour qu'un même code ne serve qu'une fois.
 */
final readonly class TotpService
{
    private const ISSUER = 'TerrilDB';
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const SECRET_BYTES = 20;
    // Une période de tolérance de chaque côté pour la dérive d'horloge.
    private const DRIFT_STEPS = 1;

    public function generateSecret(): string
    {
        return TOTP::generate(secretSize: self::SECRET_BYTES)->getSecret();
    }

    /**
     * @param non-empty-string $secret
     * @param non-empty-string $accountName
     */
    public function provisioningUri(#[\SensitiveParameter] string $secret, string $accountName): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($accountName);
        $totp->setIssuer(self::ISSUER);

        return $totp->getProvisioningUri();
    }

    /**
     * @param non-empty-string $secret
     *
     * @return int|null la période acceptée, ou null si le code est faux, mal formé ou déjà utilisé
     */
    public function verify(#[\SensitiveParameter] string $secret, string $code, int $lastUsedStep, int $timestamp): ?int
    {
        $code = str_replace(' ', '', $code);
        if (1 !== preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $totp = TOTP::createFromSecret($secret);
        $accepted = null;

        // Toutes les périodes sont comparées, sans court-circuit, pour ne pas révéler laquelle correspond.
        for ($drift = -self::DRIFT_STEPS; $drift <= self::DRIFT_STEPS; ++$drift) {
            $candidate = $timestamp + $drift * self::PERIOD;
            if ($candidate < 0) {
                continue;
            }

            $step = intdiv($candidate, self::PERIOD);
            if (hash_equals($totp->at($candidate), $code) && $step > $lastUsedStep) {
                $accepted = $step;
            }
        }

        return $accepted;
    }
}
