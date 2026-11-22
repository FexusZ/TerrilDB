<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Diagnostics\Vitess\MigrationReport;

/**
 * Les migrations de DDL en ligne d'un keyspace Vitess (`SHOW VITESS_MIGRATIONS`), en lecture seule. Piloter une migration (annuler,
 * rejouer, terminer, lancer) n'est PAS un diagnostic : c'est une instruction de structure, qui passe par les voies d'écriture habituelles
 * et leurs confirmations.
 */
interface MigrationDiagnostics
{
    public function status(): DiagnosticStatus;

    public function migrations(): MigrationReport;
}
