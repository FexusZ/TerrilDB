<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Diagnostics\Exception\InvalidDigestQueryException;

/**
 * Ce qu'on demande aux empreintes : un classement, une limite bornée et une fenêtre de temps (en heures, 24 par défaut, 168 au plus ;
 * seule ClickHouse la lit : son journal de requêtes n'a pas de remise à zéro, les autres moteurs cumulent depuis la leur). Le classement est une liste fermée, jamais un nom de
 * colonne reçu tel quel.
 */
final readonly class DigestQuery
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;
    public const DEFAULT_HOURS = 24;
    public const MAX_HOURS = 168;

    public function __construct(
        public DigestOrder $order = DigestOrder::TotalTime,
        public int $limit = self::DEFAULT_LIMIT,
        public int $hours = self::DEFAULT_HOURS,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidDigestQueryException(\sprintf('The limit is between 1 and %d.', self::MAX_LIMIT));
        }
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            throw new InvalidDigestQueryException(\sprintf('The window is between 1 and %d hours.', self::MAX_HOURS));
        }
    }

    /**
     * @throws InvalidDigestQueryException
     */
    public static function fromInput(?string $order, ?string $limit, ?string $hours = null): self
    {
        $parsedOrder = null === $order ? DigestOrder::TotalTime : (DigestOrder::tryFrom($order) ?? throw new InvalidDigestQueryException('Unknown order.'));
        if (null !== $limit && 1 !== preg_match('/^\d{1,4}$/D', $limit)) {
            throw new InvalidDigestQueryException(\sprintf('The limit is between 1 and %d.', self::MAX_LIMIT));
        }
        if (null !== $hours && 1 !== preg_match('/^\d{1,4}$/D', $hours)) {
            throw new InvalidDigestQueryException(\sprintf('The window is between 1 and %d hours.', self::MAX_HOURS));
        }

        return new self($parsedOrder, null === $limit ? self::DEFAULT_LIMIT : (int) $limit, null === $hours ? self::DEFAULT_HOURS : (int) $hours);
    }
}
