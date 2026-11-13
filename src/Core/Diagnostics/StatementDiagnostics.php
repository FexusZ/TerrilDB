<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Les requêtes coûteuses du serveur, par empreinte, lues dans les statistiques du moteur (performance_schema,
 * pg_stat_statements, query_log). Lecture seule : la remise à zéro de la fenêtre est une écriture que TerrilDB ne fait pas.
 */
interface StatementDiagnostics
{
    public function status(): DiagnosticStatus;

    public function top(DigestQuery $query): DigestReport;
}
