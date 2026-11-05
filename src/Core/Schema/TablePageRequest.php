<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

/**
 * Une page de la liste des tables : au plus `limit` tables classées à l'octet, à partir de celle qui suit `after` (pagination
 * par clé, pas par décalage : l'ordre reste stable quand des tables apparaissent), éventuellement filtrées par un morceau de
 * nom (`search`, sans casse, lu littéralement : « _ » et « % » ne sont pas des jokers).
 */
final readonly class TablePageRequest
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;

    public ?string $search;

    public function __construct(
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $after = null,
        ?string $search = null,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidSchemaException(\sprintf('A page holds between 1 and %d tables.', self::MAX_LIMIT));
        }
        $search = null === $search ? null : trim($search);
        $this->search = '' === $search ? null : $search;
    }
}
