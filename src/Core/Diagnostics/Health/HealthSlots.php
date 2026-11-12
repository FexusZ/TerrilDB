<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Health;

/**
 * Plafonne les mesures de santé simultanées d'un même compte. Une mesure attend jusqu'à 30 s DANS la requête : sans plafond,
 * quelques requêtes parallèles d'un seul jeton occuperaient tous les processus du serveur. Verrous de fichier non bloquants,
 * libérés par le système même si le processus meurt.
 */
final readonly class HealthSlots
{
    public const PER_OWNER = 2;

    public function __construct(private string $directory)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     *
     * @throws HealthBusyException
     */
    public function run(string $owner, \Closure $work): mixed
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The health slots directory cannot be created.');
        }

        $hash = hash('sha256', $owner);
        for ($slot = 0; $slot < self::PER_OWNER; ++$slot) {
            $handle = fopen($this->directory.'/'.$hash.'-'.$slot, 'c');
            if (false === $handle) {
                throw new \RuntimeException('A health slot cannot be opened.');
            }
            if (flock($handle, \LOCK_EX | \LOCK_NB)) {
                try {
                    return $work();
                } finally {
                    flock($handle, \LOCK_UN);
                    fclose($handle);
                }
            }
            fclose($handle);
        }

        throw new HealthBusyException(HealthSampler::MAX_SECONDS);
    }
}
