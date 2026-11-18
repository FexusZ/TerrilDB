<?php

declare(strict_types=1);

namespace App\Core\Optimizer;

use App\Core\Optimizer\Exception\MeasureBusyException;

/**
 * Plafonne les mesures de plan (EXPLAIN ANALYZE) simultanées d'un même compte : une mesure rejoue la requête entière et tient un
 * processus du serveur jusqu'à son délai. Verrous de fichier non bloquants, libérés par le système même si le processus meurt,
 * et en `finally` sinon (erreur, délai, connexion coupée).
 */
final readonly class MeasureSlots
{
    public const PER_OWNER = 2;
    /** Secondes à attendre avant de réessayer : le délai par défaut d'une mesure. */
    public const RETRY_AFTER = 10;

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
     * @throws MeasureBusyException
     */
    public function run(string $owner, \Closure $work): mixed
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The measure slots directory cannot be created.');
        }

        $hash = hash('sha256', $owner);
        for ($slot = 0; $slot < self::PER_OWNER; ++$slot) {
            $handle = fopen($this->directory.'/'.$hash.'-'.$slot, 'c');
            if (false === $handle) {
                throw new \RuntimeException('A measure slot cannot be opened.');
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

        throw new MeasureBusyException(self::RETRY_AFTER);
    }
}
