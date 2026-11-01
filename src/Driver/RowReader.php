<?php

declare(strict_types=1);

namespace App\Driver;

/**
 * Lit une ligne de résultat avec des types sûrs. Les moteurs rendent des chaînes, des entiers ou des booléens
 * selon le pilote : un défaut de forme est une erreur franche plutôt qu'une valeur douteuse.
 */
final readonly class RowReader
{
    /**
     * @param array<array-key, mixed> $row
     */
    public function __construct(private array $row)
    {
    }

    public function string(string $key): string
    {
        $value = $this->row[$key] ?? null;
        if (!\is_scalar($value)) {
            throw new \UnexpectedValueException(\sprintf('Column "%s" is missing or not a scalar.', $key));
        }

        return (string) $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->row[$key] ?? null;

        return null === $value || '' === $value ? null : $this->string($key);
    }

    public function int(string $key): int
    {
        $value = $this->row[$key] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d+$/D', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(\sprintf('Column "%s" is not an integer.', $key));
    }

    public function nullableInt(string $key): ?int
    {
        return null === ($this->row[$key] ?? null) ? null : $this->int($key);
    }

    public function bool(string $key): bool
    {
        $value = $this->row[$key] ?? null;

        return match (true) {
            \is_bool($value) => $value,
            1 === $value, '1' === $value => true,
            0 === $value, '0' === $value => false,
            \is_string($value) && \in_array(strtolower($value), ['yes', 't', 'true'], true) => true,
            \is_string($value) && \in_array(strtolower($value), ['no', 'f', 'false'], true) => false,
            default => throw new \UnexpectedValueException(\sprintf('Column "%s" is not a boolean.', $key)),
        };
    }
}
