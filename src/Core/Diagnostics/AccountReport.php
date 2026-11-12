<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Connection\Engine;

/**
 * Les comptes d'un serveur et l'état de leur lecture : « aucun compte » et « non lisible » ne se confondent pas.
 */
final readonly class AccountReport
{
    /**
     * @param list<Account> $accounts
     * @param list<string>  $mandatoryRoles
     * @param list<string>  $disabledPlugins plugins d'authentification utilisés par un compte mais éteints sur le serveur
     */
    public function __construct(
        public DiagnosticStatus $status,
        public Engine $engine,
        public array $accounts,
        public bool $truncated,
        public bool $activatesAllRoles = false,
        public array $mandatoryRoles = [],
        public array $disabledPlugins = [],
        /** Les droits lus sont incomplets (liste coupée ou compte ambigu) : l'absence d'un droit n'est pas prouvée. */
        public bool $privilegesIncomplete = false,
    ) {
    }

    /**
     * @return list<SecurityFinding>
     */
    public function findings(): array
    {
        return (new AccountAuditor())->audit($this);
    }
}
