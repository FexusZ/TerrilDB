<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Algorithm;

/**
 * Le verdict d'une règle : l'algorithme, si l'instruction bloque les écritures et si elle recopie la table (nuls : non vérifié),
 * ou, sans algorithme, la raison pour laquelle on ne conclut pas.
 */
final readonly class RuleVerdict
{
    private function __construct(
        public AlterAlgorithm $algorithm,
        public ?bool $blocksWrites,
        public ?bool $copiesTable,
        public ?string $reason,
    ) {
    }

    public static function of(AlterAlgorithm $algorithm, ?bool $blocksWrites, ?bool $copiesTable): self
    {
        return new self($algorithm, $blocksWrites, $copiesTable, null);
    }

    public static function unknown(string $reason): self
    {
        return new self(AlterAlgorithm::Unknown, null, null, $reason);
    }
}
