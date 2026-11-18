<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Log;

use App\Core\Diagnostics\DiagnosticStatus;

/**
 * Les lignes les plus récentes du journal d'erreurs, avec l'état de la source : une liste vide d'une source illisible ne passe
 * pas pour « aucune erreur ».
 */
final readonly class ErrorLogReport
{
    /**
     * @param list<ErrorLogEntry> $entries les plus récentes d'abord
     */
    public function __construct(
        public DiagnosticStatus $status,
        public array $entries,
        /** Vrai si le serveur en a davantage que la borne posée. */
        public bool $truncated,
    ) {
    }
}
