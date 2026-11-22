<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\ServerDiagnostics;
use App\Core\Diagnostics\VariableQuery;
use App\Core\Diagnostics\VariableReport;

/**
 * Ce qu'on a lu d'UN tablet pour le comparer aux autres. `failure` (code stable, jamais le message du serveur) quand il n'a pas pu être
 * ouvert ou lu ; un thème illisible porte son propre état dans son rapport.
 */
final readonly class TabletSnapshot
{
    public function __construct(
        public VitessTablet $tablet,
        public ?ReplicationReport $replication,
        public ?VariableReport $variables,
        public ?string $failure,
    ) {
    }

    /**
     * Lit la réplication et les variables MODIFIÉES du tablet (lecture seule). Un thème qui échoue reste nul sans gêner l'autre ;
     * aucun des deux lisible = `failure: unreadable`.
     */
    public static function read(VitessTablet $tablet, ServerDiagnostics $diagnostics): self
    {
        try {
            $replication = $diagnostics->replication()->replication();
        } catch (\Throwable) {
            $replication = null;
        }
        try {
            $variables = $diagnostics->variables()->variables(new VariableQuery(changedOnly: true, limit: VariableQuery::MAX_LIMIT));
        } catch (\Throwable) {
            $variables = null;
        }

        return new self($tablet, $replication, $variables, null === $replication && null === $variables ? 'unreadable' : null);
    }
}
