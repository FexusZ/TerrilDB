<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * Une instruction d'écriture prête à partir : le SQL avec ses paramètres liés (c'est ce qui s'exécute), et `display`, le même
 * SQL avec les valeurs écrites en clair, seulement pour le montrer. `matchSql` compte les lignes que la clause WHERE retrouve
 * (modification et suppression) : il en faut exactement une.
 */
final readonly class DataStatement
{
    /**
     * @param array<int|string, bool|float|int|string> $params
     * @param array<int|string, bool|float|int|string> $matchParams
     */
    public function __construct(
        public string $sql,
        public array $params,
        public string $display,
        public string $operation,
        public string $subject,
        public bool $destructive,
        public ?string $matchSql,
        public array $matchParams,
    ) {
    }
}
