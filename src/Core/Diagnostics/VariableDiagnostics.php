<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Variables de configuration d'un serveur, en lecture seule : requêtes de catalogue fixes, jamais un SET (même PERSIST), jamais
 * la valeur d'un secret. Une source éteinte ou un droit manquant se dit dans l'état.
 */
interface VariableDiagnostics
{
    public function status(): DiagnosticStatus;

    public function variables(VariableQuery $query): VariableReport;
}
