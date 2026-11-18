<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Connection\Engine;

/**
 * Le résultat de l'audit de configuration. `evaluated` sur `rulesTotal` dit combien de règles avaient leurs variables lisibles :
 * « aucun constat » avec peu de règles évaluées ne veut pas dire « tout va bien ».
 */
final readonly class ConfigAuditReport
{
    /**
     * @param list<ConfigFinding> $findings
     */
    public function __construct(
        public DiagnosticStatus $status,
        public Engine $engine,
        public array $findings,
        public int $evaluated,
        public int $rulesTotal,
    ) {
    }
}
