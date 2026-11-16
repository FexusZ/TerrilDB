<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Diagnostics\Replication\ReplicationReport;

/**
 * L'état de la réplication du serveur : rôle, fils, retard, dernière erreur, réglages. Lectures de catalogue fixes, en lecture
 * seule : jamais START, STOP ni CHANGE, jamais l'hôte ni le compte de la source. Un droit manquant, une version trop ancienne ou une
 * source éteinte se disent dans l'état ; « pas de réplication configurée » est un résultat, pas une erreur.
 */
interface ReplicationDiagnostics
{
    public function status(): DiagnosticStatus;

    public function replication(): ReplicationReport;
}
