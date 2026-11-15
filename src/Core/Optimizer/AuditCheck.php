<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

/**
 * Une règle d'exploitation qui n'a pas pu juger : jamais un constat, et jamais une erreur. `reason` est un code stable de la
 * source (performance_schema_off, permission_denied…), `detail` un chiffre utile (la durée de collecte en secondes).
 */
final readonly class AuditCheck
{
    public function __construct(
        public FindingRule $rule,
        public CheckState $state,
        public ?string $reason = null,
        public ?string $detail = null,
    ) {
    }
}
