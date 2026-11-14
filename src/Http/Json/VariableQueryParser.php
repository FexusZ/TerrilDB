<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Diagnostics\Exception\InvalidVariableQueryException;
use App\Core\Diagnostics\VariableQuery;
use Symfony\Component\HttpFoundation\Request;

/**
 * Les paramètres de la liste des variables (`q`, `changed`, `limit`), lus pareil par l'interface et par l'API : tout ce qui n'est
 * pas une valeur attendue est refusé (422), jamais corrigé en silence.
 */
final class VariableQueryParser
{
    public static function fromRequest(Request $request): VariableQuery
    {
        $search = $request->query->get('q');
        $changed = $request->query->get('changed');
        $limit = $request->query->get('limit');

        return self::build(
            \is_string($search) ? $search : null,
            \is_string($changed) ? $changed : null,
            \is_string($limit) ? $limit : null,
        );
    }

    public static function build(?string $search, ?string $changed, ?string $limit): VariableQuery
    {
        if (null !== $changed && !\in_array($changed, ['0', '1', 'true', 'false', ''], true)) {
            throw new InvalidVariableQueryException('"changed" is a boolean.');
        }
        $count = VariableQuery::DEFAULT_LIMIT;
        if (null !== $limit) {
            $value = filter_var($limit, \FILTER_VALIDATE_INT);
            if (!\is_int($value)) {
                throw new InvalidVariableQueryException('"limit" is an integer.');
            }
            $count = $value;
        }

        return new VariableQuery($search, \in_array($changed, ['1', 'true'], true), $count);
    }
}
