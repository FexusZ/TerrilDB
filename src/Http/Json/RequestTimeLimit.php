<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Query\Exception\InvalidQueryOptionsException;

/**
 * La durée maximale qu'un client peut demander pour une requête ou un script. Le reverse proxy coupe la réponse après
 * `TERRILDB_PROXY_TIMEOUT` secondes : une exécution plus longue donnerait un 504 au client pendant que la base continue.
 * La limite est donc ce délai moins une marge, et une durée au-dessus est REFUSÉE (422), jamais ramenée en silence.
 * La même valeur règle le proxy de production (docker/prod/nginx.conf.template).
 */
final readonly class RequestTimeLimit
{
    /** Ce qu'on garde pour la réponse elle-même (mise en forme, réseau) une fois l'exécution arrêtée. */
    public const MARGIN_MS = 20_000;
    public const MIN_PROXY_SECONDS = 30;
    public const MAX_PROXY_SECONDS = 3600;

    private int $maxMs;

    public function __construct(int $proxyTimeoutSeconds)
    {
        if ($proxyTimeoutSeconds < self::MIN_PROXY_SECONDS || $proxyTimeoutSeconds > self::MAX_PROXY_SECONDS) {
            throw new \InvalidArgumentException(\sprintf('TERRILDB_PROXY_TIMEOUT must be between %d and %d seconds.', self::MIN_PROXY_SECONDS, self::MAX_PROXY_SECONDS));
        }
        $this->maxMs = $proxyTimeoutSeconds * 1000 - self::MARGIN_MS;
    }

    public function maxMs(): int
    {
        return $this->maxMs;
    }

    public function proxySeconds(): int
    {
        return intdiv($this->maxMs + self::MARGIN_MS, 1000);
    }

    /**
     * @throws InvalidQueryOptionsException
     */
    public function timeout(?int $requested, int $default): int
    {
        if (null === $requested) {
            return min($default, $this->maxMs);
        }
        if ($requested > $this->maxMs) {
            throw new InvalidQueryOptionsException(\sprintf('The timeout cannot exceed %d ms on this server.', $this->maxMs));
        }

        return $requested;
    }
}
