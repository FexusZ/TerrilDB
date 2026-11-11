<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Diagnostics\Exception\InvalidDigestQueryException;

/**
 * Ce qu'on demande aux empreintes : un classement et une limite bornée. Le classement est une liste fermée, jamais un nom de
 * colonne reçu tel quel.
 */
final readonly class DigestQuery
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    public function __construct(
        public DigestOrder $order = DigestOrder::TotalTime,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidDigestQueryException(\sprintf('The limit is between 1 and %d.', self::MAX_LIMIT));
        }
    }

    /**
     * @throws InvalidDigestQueryException
     */
    public static function fromInput(?string $order, ?string $limit): self
    {
        $parsedOrder = null === $order ? DigestOrder::TotalTime : (DigestOrder::tryFrom($order) ?? throw new InvalidDigestQueryException('Unknown order.'));
        if (null === $limit) {
            return new self($parsedOrder);
        }
        if (1 !== preg_match('/^\d{1,4}$/D', $limit)) {
            throw new InvalidDigestQueryException(\sprintf('The limit is between 1 and %d.', self::MAX_LIMIT));
        }

        return new self($parsedOrder, (int) $limit);
    }
}
