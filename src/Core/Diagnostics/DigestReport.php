<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Les empreintes les plus coûteuses avec l'état de la source : « pas de conclusion » (source éteinte, droit manquant) ne se
 * confond pas avec « aucune requête ».
 */
final readonly class DigestReport
{
    /**
     * @param list<StatementDigest> $digests
     */
    public function __construct(
        public DiagnosticStatus $status,
        public DigestOrder $order,
        public array $digests,
        /** Fenêtre de temps lue, en heures, quand la source en a une (ClickHouse) ; nulle quand elle cumule depuis sa remise à zéro. */
        public ?int $windowHours = null,
    ) {
    }
}
