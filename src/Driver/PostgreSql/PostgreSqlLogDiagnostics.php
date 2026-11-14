<?php

declare(strict_types=1);

namespace App\Driver\PostgreSql;

use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\Log\ErrorLogReport;
use App\Core\Diagnostics\Log\SlowQueryReport;
use App\Core\Diagnostics\LogDiagnostics;

/**
 * PostgreSQL n'a aucune table SQL pour ses journaux : ils vont dans un fichier du serveur (ou le collecteur du système). Les
 * lire demanderait `pg_read_file` ou `pg_ls_dir`, c'est-à-dire un accès aux fichiers de l'hôte, que TerrilDB ne fait jamais.
 * L'état le dit, avec sa raison ; aucune requête n'est envoyée.
 */
final readonly class PostgreSqlLogDiagnostics implements LogDiagnostics
{
    public function status(): DiagnosticStatus
    {
        return DiagnosticStatus::unsupported('log_is_a_file');
    }

    public function errorLog(): ErrorLogReport
    {
        return new ErrorLogReport($this->status(), [], false);
    }

    public function slowQueries(): SlowQueryReport
    {
        return new SlowQueryReport($this->status(), [], false, null);
    }
}
