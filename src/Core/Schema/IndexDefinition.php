<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

final readonly class IndexDefinition
{
    /**
     * @param list<string> $columns   dans l'ordre de l'index (une expression est donnée par son texte)
     * @param ?int         $sizeBytes place occupée sur disque, quand le moteur la donne (nul sinon : jamais comparée ni utilisée par un brouillon)
     * @param ?LockReason  $lock      une particularité que le modèle ne sait pas redire (index invisible…) : l'index ne se recrée pas
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public bool $primary,
        public ?int $sizeBytes = null,
        public ?LockReason $lock = null,
    ) {
        if ('' === $name || [] === $columns) {
            throw new InvalidSchemaException('An index needs a name and at least one column.');
        }
        if ($primary && !$unique) {
            throw new InvalidSchemaException('A primary key is always unique.');
        }
    }
}
