<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\Log\ErrorLogReport;
use App\Core\Diagnostics\Log\SlowQueryReport;

/**
 * Journaux du serveur lus EN SQL, jamais par un fichier de l'hôte (ni `pg_read_file`, ni `LOAD_FILE`) : journal d'erreurs
 * (`performance_schema.error_log` de MySQL) et journal des requêtes lentes quand `log_output` contient TABLE. Une source
 * absente, éteinte ou refusée se dit dans l'état, jamais par une liste vide. Les textes sont masqués puis raccourcis.
 */
interface LogDiagnostics
{
    /**
     * Lisible si au moins une des sources l'est ; sinon l'état de la plus parlante (le journal d'erreurs).
     *
     * @throws DiagnosticsFailedException
     */
    public function status(): DiagnosticStatus;

    /**
     * @throws DiagnosticsFailedException
     */
    public function errorLog(): ErrorLogReport;

    /**
     * @throws DiagnosticsFailedException
     */
    public function slowQueries(): SlowQueryReport;
}
