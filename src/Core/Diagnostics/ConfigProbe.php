<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Connection\Engine;

/**
 * Le carnet d'une passe d'audit : les valeurs lisibles, les constats posés et le nombre de règles qui ont pu être jugées.
 *
 * @internal
 */
final class ConfigProbe
{
    /** @var list<ConfigFinding> */
    public array $findings = [];
    public int $evaluated = 0;

    /**
     * @param array<string, string> $values les seules variables lisibles (ni masquées, ni nulles)
     */
    public function __construct(public readonly Engine $engine, private readonly array $values)
    {
    }

    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    public static function isOn(string $value): bool
    {
        return \in_array(strtolower($value), ['on', '1', 'true', 'yes'], true);
    }

    public static function isOff(string $value): bool
    {
        return \in_array(strtolower($value), ['off', '0', 'false', 'no'], true);
    }

    /**
     * Une règle sur UNE variable : jugée seulement si elle est lisible.
     *
     * @param \Closure(string): bool $bad
     * @param ?string                $setTo la valeur à poser pour la suggestion (nul : variable figée, à changer dans le fichier de configuration)
     */
    public function when(ConfigRule $rule, string $name, \Closure $bad, Severity $severity, string $recommended, ?string $setTo): void
    {
        $value = $this->get($name);
        if (null === $value) {
            return;
        }
        ++$this->evaluated;
        if ($bad($value)) {
            $this->add($rule, $severity, $name, $value, $recommended, $setTo);
        }
    }

    public function add(ConfigRule $rule, Severity $severity, string $name, string $value, string $recommended, ?string $setTo): void
    {
        $this->findings[] = new ConfigFinding($rule, $severity, $name, $value, $recommended, null === $setTo ? null : $this->suggestion($name, $setTo));
    }

    private function suggestion(string $name, string $setTo): string
    {
        return match ($this->engine) {
            Engine::PostgreSql => "ALTER SYSTEM SET {$name} = '{$setTo}'; SELECT pg_reload_conf();",
            Engine::MariaDb => "SET GLOBAL {$name} = {$setTo};",
            default => "SET PERSIST {$name} = {$setTo};",
        };
    }
}
