<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;

/**
 * Un index tel qu'on le veut (la clé primaire en est un, `primary`). `kind` et `granularity` ne servent qu'aux index de saut
 * de ClickHouse (minmax, bloom_filter, set(n)) ; ailleurs ils restent nuls.
 */
final readonly class IndexDraft
{
    private const string KIND = '/^(?:minmax|bloom_filter|set\(\d{1,6}\))$/';

    /**
     * @param list<string> $columns
     */
    public function __construct(
        public ?string $originalName,
        public string $name,
        public array $columns,
        public bool $unique,
        public bool $primary,
        public ?string $kind,
        public ?int $granularity,
    ) {
        NameRule::check($name, 'index');
        if ([] === $columns) {
            throw new InvalidSchemaChangeException('An index needs at least one column.');
        }
        foreach ($columns as $column) {
            NameRule::check($column, 'index column');
        }
        if ($primary && !$unique) {
            throw new InvalidSchemaChangeException('A primary key is always unique.');
        }
        if (null !== $kind && 1 !== preg_match(self::KIND, $kind)) {
            throw new InvalidSchemaChangeException('This index kind is not supported.');
        }
        if (null !== $granularity && ($granularity < 1 || $granularity > 1_000_000)) {
            throw new InvalidSchemaChangeException('The granularity must be a positive number.');
        }
    }
}
