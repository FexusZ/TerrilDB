<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Transactions longues et verrous en attente. Requêtes de catalogue fixes, en lecture seule : jamais un KILL, jamais le texte
 * d'un client. Une source éteinte ou un droit manquant se dit dans l'état.
 */
interface LockDiagnostics
{
    public function status(): DiagnosticStatus;

    public function locks(): LockReport;

    /**
     * Les sessions qui tiennent la table (dans la base ou le schéma de la connexion) : un ALTER attendrait leur fin. Lecture
     * seule ; une source éteinte ou un droit manquant se dit dans l'état.
     */
    public function tableHolders(string $table): TableHolders;
}
