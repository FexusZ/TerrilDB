<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

/**
 * Le découpage d'une table en partitions, en LECTURE seule : méthode, expression (ou colonnes) de partitionnement, sous-découpage
 * éventuel et liste des partitions. Hors de l'empreinte de structure : les lignes et les tailles bougent sans que la structure change.
 */
final readonly class PartitioningInfo
{
    /** Expression d'un KEY() sans colonne : le moteur se sert de la clé primaire et n'écrit aucune expression. */
    public const PRIMARY_KEY = '(clé primaire)';

    /**
     * @param list<PartitionDefinition> $partitions
     */
    public function __construct(
        public PartitionMethod $method,
        public string $expression,
        public ?PartitionMethod $subMethod,
        public ?string $subExpression,
        public array $partitions,
    ) {
        if ('' === $expression || [] === $partitions) {
            throw new InvalidSchemaException('A partitioning needs an expression and at least one partition.');
        }
    }

    public function count(): int
    {
        return \count($this->partitions);
    }

    public function estimatedRows(): ?int
    {
        return self::sum(array_map(static fn (PartitionDefinition $p): ?int => $p->estimatedRows, $this->partitions));
    }

    public function dataBytes(): ?int
    {
        return self::sum(array_map(static fn (PartitionDefinition $p): ?int => $p->dataBytes, $this->partitions));
    }

    /**
     * @param list<int|null> $values
     */
    private static function sum(array $values): ?int
    {
        $known = array_filter($values, static fn (?int $v): bool => null !== $v);

        return [] === $known ? null : array_sum($known);
    }
}
