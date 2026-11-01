<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Driver\TargetConnection;
use App\Core\KeyValue\Exception\InvalidKeyException;
use App\Http\Json\KeyPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * La navigation par clés d'une base Redis : liste paginée (SCAN) et contenu d'une clé. Lecture seule ; l'écriture passe
 * par l'exécution de commandes (QueryController), qui a son garde et son audit. Le nom d'une clé voyage en paramètre
 * de requête : il peut contenir des « / », des « ? », de l'unicode.
 */
final readonly class KeysController
{
    private const ID = ['id' => '[0-9a-fA-F-]{36}'];
    private const DEFAULT_COUNT = 100;
    private const MAX_COUNT = 1000;
    private const DEFAULT_LIMIT = 200;
    private const MAX_LIMIT = 1000;
    private const PATTERN_MAX_LENGTH = 512;

    public function __construct(private TargetAccess $targets)
    {
    }

    #[Route('/web/connections/{id}/keys', requirements: self::ID, methods: ['GET'])]
    public function keys(string $id, Request $request): JsonResponse
    {
        $pattern = self::text($request, 'pattern') ?? '*';
        if (\strlen($pattern) > self::PATTERN_MAX_LENGTH) {
            throw new InvalidKeyException('The pattern is too long.');
        }
        $cursor = self::text($request, 'cursor');
        if (null !== $cursor && 1 !== preg_match('/^\d{1,20}$/D', $cursor)) {
            throw new InvalidKeyException('The cursor is not valid.');
        }
        $count = self::number($request, 'count', self::DEFAULT_COUNT, self::MAX_COUNT);

        [$page, $total] = $this->targets->withSaved(Uuid::fromString($id), static function (TargetConnection $target) use ($pattern, $cursor, $count): array {
            $keys = $target->keys();

            return [$keys->scan($pattern, $cursor, $count), $keys->size()];
        });

        return new JsonResponse([
            'keys' => array_map(KeyPresenter::entry(...), $page->entries),
            'nextCursor' => $page->nextCursor,
            'total' => $total,
        ]);
    }

    #[Route('/web/connections/{id}/key', requirements: self::ID, methods: ['GET'])]
    public function key(string $id, Request $request): JsonResponse
    {
        $name = self::text($request, 'name');
        if (null === $name || '' === $name) {
            throw new InvalidKeyException('The key name is required.');
        }
        $limit = self::number($request, 'limit', self::DEFAULT_LIMIT, self::MAX_LIMIT);

        $detail = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target) => $target->keys()->inspect($name, $limit));

        return new JsonResponse(['key' => KeyPresenter::detail($detail)]);
    }

    private static function text(Request $request, string $name): ?string
    {
        $value = $request->query->get($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private static function number(Request $request, string $name, int $default, int $max): int
    {
        $value = self::text($request, $name);
        if (null === $value) {
            return $default;
        }
        if (1 !== preg_match('/^\d{1,6}$/D', $value) || (int) $value < 1 || (int) $value > $max) {
            throw new InvalidKeyException(\sprintf('"%s" must be between 1 and %d.', $name, $max));
        }

        return (int) $value;
    }
}
