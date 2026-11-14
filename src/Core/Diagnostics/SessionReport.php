<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Les sessions d'un serveur, avec l'état de la source : une liste vide d'une source illisible ne passe pas pour « personne ».
 */
final readonly class SessionReport
{
    /**
     * @param list<ServerSession> $sessions
     */
    public function __construct(
        public DiagnosticStatus $status,
        public array $sessions,
        /** Vrai si le serveur en a davantage que la borne posée. */
        public bool $truncated,
    ) {
    }
}
