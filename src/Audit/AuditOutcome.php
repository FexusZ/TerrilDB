<?php

declare(strict_types=1);

namespace App\Audit;

enum AuditOutcome: string
{
    case Success = 'success';
    /** Refusé avant d'agir (garde, plafond, droits). */
    case Refused = 'refused';
    /** Tenté, mais en échec (mauvais mot de passe, erreur du moteur, délai dépassé). */
    case Failed = 'failed';
}
