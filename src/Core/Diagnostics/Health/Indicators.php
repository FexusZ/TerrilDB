<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

/**
 * Fabrique d'indicateurs : une valeur absente donne « inconnu », jamais zéro ; le seuil d'avertissement se compare à la valeur
 * arrondie à deux décimales, et seulement quand l'intervalle a assez d'activité pour que le rapport veuille dire quelque chose.
 */
final class Indicators
{
    public static function rate(string $key, ?float $perSecond, ?float $warnAt = null): HealthIndicator
    {
        return self::make($key, 'per_second', $perSecond, $warnAt, true, true);
    }

    public static function count(string $key, ?float $value, ?float $warnAt = null): HealthIndicator
    {
        return self::make($key, 'count', $value, $warnAt, true, true);
    }

    /**
     * @param float|null $enough activité minimale (dénominateur) pour juger ; en deçà, le pourcentage est montré sans avertissement
     * @param bool       $higher vrai : avertissement quand la valeur atteint le seuil ; faux : quand elle passe sous le seuil
     */
    public static function percent(string $key, ?float $numerator, ?float $denominator, ?float $warnAt, float $enough = 1.0, bool $higher = true, bool $complement = false): HealthIndicator
    {
        if (null === $numerator || null === $denominator || $denominator <= 0.0) {
            return new HealthIndicator($key, null, 'percent', HealthLevel::Unknown, $warnAt);
        }
        $value = 100.0 * $numerator / $denominator;
        if ($complement) {
            $value = 100.0 - $value;
        }

        return self::make($key, 'percent', $value, $warnAt, $higher, $denominator >= $enough);
    }

    private static function make(string $key, string $unit, ?float $value, ?float $warnAt, bool $higher, bool $judge): HealthIndicator
    {
        if (null === $value) {
            return new HealthIndicator($key, null, $unit, HealthLevel::Unknown, $warnAt);
        }
        $value = round($value, 2);
        $warn = $judge && null !== $warnAt && ($higher ? $value >= $warnAt : $value < $warnAt);

        return new HealthIndicator($key, $value, $unit, $warn ? HealthLevel::Warning : HealthLevel::Ok, $warnAt);
    }
}
