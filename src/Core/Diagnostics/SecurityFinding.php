<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Une constatation de l'audit des comptes. `detail` est une donnée courte (un droit, des hôtes, des rôles), jamais une empreinte
 * de mot de passe ni du texte du moteur ; `currentAccount` vaut vrai pour le compte que TerrilDB utilise lui-même.
 */
final readonly class SecurityFinding
{
    public function __construct(
        public AccountRule $rule,
        public Severity $severity,
        public ?string $account,
        public ?string $detail,
        public bool $currentAccount,
    ) {
    }
}
