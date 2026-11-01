<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Driver\TargetConnection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class SchemaController
{
    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(private TargetAccess $targets)
    {
    }

    #[Route('/web/connections/{id}/tables', requirements: self::ID, methods: ['GET'])]
    public function tables(string $id): JsonResponse
    {
        $tables = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target): array => $target->introspector()->listTables());

        return new JsonResponse(['tables' => array_map(SchemaPresenter::summary(...), $tables)]);
    }

    #[Route('/web/connections/{id}/tables/{name}', requirements: self::ID + ['name' => '[^/]+'], methods: ['GET'])]
    public function table(string $id, string $name): JsonResponse
    {
        $table = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target) => $target->introspector()->describeTable($name));

        return new JsonResponse(['table' => SchemaPresenter::table($table)]);
    }
}
