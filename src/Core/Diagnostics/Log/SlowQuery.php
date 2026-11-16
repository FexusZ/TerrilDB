<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Log;

use App\Core\Diagnostics\QueryText;
use App\Core\Guard\SqlDialect;

/**
 * Une ligne du journal des requêtes lentes. Comme pour une session, le texte est débarrassé de ses littéraux et de ses
 * commentaires (`QueryText`) puis raccourci ; un texte illisible n'est pas rendu (`queryRedacted`). L'hôte du client n'est pas gardé.
 */
final readonly class SlowQuery
{
    public ?string $query;
    public bool $queryRedacted;

    public function __construct(
        /** Début de l'exécution (secondes Unix). */
        public ?float $startedAt,
        public ?string $user,
        public ?string $database,
        public ?float $durationMs,
        public ?float $lockMs,
        public ?int $rowsSent,
        public ?int $rowsExamined,
        ?string $query,
        SqlDialect $dialect,
    ) {
        $text = QueryText::of($query, $dialect);
        $this->query = $text->text;
        $this->queryRedacted = $text->redacted;
    }
}
