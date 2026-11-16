<?php

declare(strict_types=1);

namespace App\Core\Data;

use App\Core\Connection\Engine;
use App\Core\Data\Exception\InvalidDataChangeException;
use App\Core\Sql\SqlText;
use App\Core\Sql\UnsafeSqlNameException;

/**
 * Écrit les valeurs d'une instruction : un paramètre lié (`?`, ou `{pN:Type}` pour ClickHouse) ou, pour l'affichage seulement,
 * le littéral. Sait aussi citer un identifiant à la façon du moteur ({@see SqlText}, qui refuse un nom hostile).
 *
 * @internal
 */
final class Binder
{
    /** @var array<int|string, bool|float|int|string> */
    private array $params = [];
    private int $next = 1;

    public function __construct(private readonly Engine $engine, private readonly bool $literal)
    {
    }

    public function quote(string $name): string
    {
        try {
            return SqlText::quoteIdentifier($this->engine, $name);
        } catch (UnsafeSqlNameException) {
            throw new InvalidDataChangeException('A table or column name contains a control character, a line separator or invalid text.');
        }
    }

    public function bind(bool|float|int|string $value, string $columnType): string
    {
        if ($this->literal) {
            return $this->literal($value);
        }
        if (Engine::ClickHouse === $this->engine) {
            $name = 'p'.$this->next++;
            $this->params[$name] = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            return '{'.$name.':'.self::clickHouseType($columnType).'}';
        }
        $this->params[] = $value;

        return '?';
    }

    /**
     * @return array<int|string, bool|float|int|string>
     */
    public function params(): array
    {
        return $this->params;
    }

    private function literal(bool|float|int|string $value): string
    {
        if (\is_bool($value)) {
            return Engine::PostgreSql === $this->engine || Engine::ClickHouse === $this->engine ? ($value ? 'true' : 'false') : ($value ? '1' : '0');
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return match ($this->engine) {
            Engine::PostgreSql => "'".str_replace("'", "''", $value)."'",
            default => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'",
        };
    }

    /** Le type d'un paramètre ClickHouse : celui de la colonne, sans l'enveloppe LowCardinality qui n'y est pas admise. */
    private static function clickHouseType(string $type): string
    {
        return str_starts_with($type, 'LowCardinality(') && str_ends_with($type, ')') ? substr($type, 15, -1) : $type;
    }
}
