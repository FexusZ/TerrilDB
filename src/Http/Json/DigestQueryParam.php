<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Diagnostics\DigestQuery;
use App\Core\Diagnostics\Exception\InvalidDigestQueryException;
use Symfony\Component\HttpFoundation\Request;

/**
 * `?order=&limit=` des routes d'empreintes : la validation est celle du cœur, ici on ne fait que refuser ce qui n'est pas un texte.
 */
final class DigestQueryParam
{
    /**
     * @throws InvalidDigestQueryException
     */
    public static function fromQuery(Request $request): DigestQuery
    {
        $all = $request->query->all();

        return DigestQuery::fromInput(self::text($all['order'] ?? null), self::text($all['limit'] ?? null));
    }

    private static function text(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return \is_string($value) ? $value : throw new InvalidDigestQueryException('A parameter is a single text.');
    }
}
