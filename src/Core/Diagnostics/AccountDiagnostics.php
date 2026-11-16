<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Comptes, rôles et privilèges d'un serveur. Requêtes de catalogue fixes, en lecture seule, qui ne lisent JAMAIS une empreinte de
 * mot de passe (au plus un booléen « vide ou non » calculé par le serveur). Un compte qui ne lit pas le catalogue reçoit un état.
 */
interface AccountDiagnostics
{
    public function status(): DiagnosticStatus;

    public function accounts(): AccountReport;
}
