<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Diagnostics\Health\HealthSampler;
use App\Core\Query\Exception\InvalidQueryOptionsException;

/**
 * L'intervalle de mesure demandé (`seconds`) : absent = la valeur par défaut, sinon un entier de 1 à 30. Le texte d'une adresse
 * et le nombre d'un outil MCP passent par ici ; le cœur borne de toute façon.
 */
final class HealthInterval
{
    /**
     * @throws InvalidQueryOptionsException
     */
    public static function parse(mixed $value): int
    {
        if (null === $value || '' === $value) {
            return HealthSampler::DEFAULT_SECONDS;
        }
        $seconds = filter_var($value, \FILTER_VALIDATE_INT);
        if (!\is_int($seconds) || $seconds < HealthSampler::MIN_SECONDS || $seconds > HealthSampler::MAX_SECONDS) {
            throw new InvalidQueryOptionsException(\sprintf('The interval is %d to %d seconds.', HealthSampler::MIN_SECONDS, HealthSampler::MAX_SECONDS));
        }

        return $seconds;
    }
}
