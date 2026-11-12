<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Guard\SqlDialect;

/**
 * Une famille de requêtes du serveur, regroupées par empreinte : le texte est normalisé par le moteur (les valeurs sont
 * remplacées par des marques), sauf les instructions utilitaires de PostgreSQL (`ALTER ROLE … PASSWORD '…'`) : il passe donc
 * toujours par `QueryText` (même masquage des littéraux que les sessions) et n'est jamais reconstruit ici. Les durées sont en millisecondes, quelle que soit l'unité de
 * la source (picosecondes pour performance_schema). Ce que la source ne donne pas reste nul.
 */
final readonly class StatementDigest
{
    public ?string $text;
    public bool $textRedacted;

    public function __construct(
        ?string $text,
        public ?string $database,
        public int $calls,
        public float $totalMs,
        public float $meanMs,
        public ?float $maxMs,
        public ?int $rowsExamined,
        public ?int $rowsReturned,
        /** Appels qui n'ont utilisé aucun index. */
        public ?int $noIndexUsed,
        public ?int $tmpDiskTables,
        public ?int $sortMergePasses,
        /** Instant ISO 8601 UTC. */
        public ?string $firstSeen,
        public ?string $lastSeen,
        SqlDialect $dialect,
    ) {
        if ($calls < 1) {
            throw new \InvalidArgumentException('A digest has at least one call.');
        }
        $masked = QueryText::of($text, $dialect);
        $this->text = $masked->text;
        $this->textRedacted = $masked->redacted;
    }

    /**
     * Lignes examinées pour une ligne renvoyée (1 000 pour 1 trahit un index manquant) ; rien renvoyé compte pour une.
     */
    public function ratio(): ?float
    {
        if (null === $this->rowsExamined) {
            return null;
        }

        return round($this->rowsExamined / max(1, $this->rowsReturned ?? 1), 1);
    }
}
