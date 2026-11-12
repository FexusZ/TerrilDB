<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\HealthDiagnostics;
use App\Core\Query\Exception\InvalidQueryOptionsException;

/**
 * Prend deux relevés à quelques secondes d'intervalle et en tire les indicateurs. L'attente est bornée (1 à 30 s) et se fait
 * dans la requête : aucune boucle, aucun relevé conservé. Une source illisible ne coûte aucune attente.
 */
final readonly class HealthSampler
{
    public const MIN_SECONDS = 1;
    public const MAX_SECONDS = 30;
    public const DEFAULT_SECONDS = 5;

    public function __construct(private Pause $pause)
    {
    }

    /**
     * @throws InvalidQueryOptionsException si l'intervalle sort de 1 à 30 secondes
     * @throws DiagnosticsFailedException
     */
    public function measure(HealthDiagnostics $source, int $seconds = self::DEFAULT_SECONDS): HealthReport
    {
        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new InvalidQueryOptionsException(\sprintf('The interval is %d to %d seconds.', self::MIN_SECONDS, self::MAX_SECONDS));
        }

        $status = $source->status();
        if (!$status->isAvailable()) {
            return HealthReport::unreadable($status);
        }

        $first = $source->sample();
        $this->pause->sleep((float) $seconds);
        $second = $source->sample();

        $window = new HealthWindow($first, $second);
        $discarded = $window->resetReason();

        return new HealthReport($status, $first->takenAt, $second->takenAt, $window->seconds(), null === $discarded ? $source->indicators($window) : [], $discarded);
    }
}
