<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Les variables d'un serveur avec l'état de la source : une liste vide d'une source illisible ne passe pas pour « aucune variable ».
 */
final readonly class VariableReport
{
    /**
     * @param list<ServerVariable> $variables classées par nom, à l'octet
     */
    public function __construct(
        public DiagnosticStatus $status,
        public array $variables,
        /** Vrai si le serveur en a davantage que la limite demandée. */
        public bool $truncated,
    ) {
    }
}
