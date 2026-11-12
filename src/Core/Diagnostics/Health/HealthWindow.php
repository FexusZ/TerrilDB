<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

/**
 * Deux relevés et ce qu'on peut en dire : écarts et taux par seconde, jamais calculés sur un compteur cumulé seul. Un compteur
 * remis à zéro, absent ou non numérique n'a pas d'écart (null) plutôt qu'une valeur inventée.
 */
final readonly class HealthWindow
{
    private const NUMBER = '/^-?\d+(\.\d+)?$/D';

    public function __construct(public HealthSample $before, public HealthSample $after)
    {
    }

    /**
     * Durée réelle entre les deux relevés (pas celle demandée).
     */
    public function seconds(): float
    {
        return $this->after->takenAt - $this->before->takenAt;
    }

    /**
     * Pourquoi la mesure est inutilisable : le serveur a redémarré ou ses statistiques ont été remises à zéro (`counters_reset`),
     * ou les relevés n'ont pas de durée (`invalid_interval`).
     */
    public function resetReason(): ?string
    {
        if ($this->seconds() <= 0.0) {
            return 'invalid_interval';
        }
        if (null !== $this->before->uptime && null !== $this->after->uptime && $this->after->uptime < $this->before->uptime) {
            return 'counters_reset';
        }
        if ($this->before->epoch !== $this->after->epoch) {
            return 'counters_reset';
        }

        return null;
    }

    public function delta(string $name): ?float
    {
        $before = self::number($this->before->counters[$name] ?? null);
        $after = self::number($this->after->counters[$name] ?? null);
        if (null === $before || null === $after || $after < $before) {
            return null;
        }

        return $after - $before;
    }

    public function rate(string $name): ?float
    {
        $delta = $this->delta($name);
        $seconds = $this->seconds();

        return null === $delta || $seconds <= 0.0 ? null : $delta / $seconds;
    }

    /**
     * Valeur instantanée (connexions courantes, plafond…) : celle du dernier relevé.
     */
    public function gauge(string $name): ?float
    {
        return self::number($this->after->counters[$name] ?? null);
    }

    private static function number(?string $value): ?float
    {
        return null !== $value && 1 === preg_match(self::NUMBER, $value) ? (float) $value : null;
    }
}
