<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Driver\TargetConnection;
use App\Core\Schema\DatabaseSummary;
use App\Http\Json\SchemaPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class SchemaController
{
    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(private TargetAccess $targets)
    {
    }

    /**
     * Les bases que le compte voit sur le serveur ; celles du moteur (information_schema…) sont masquées sauf `?system=1`.
     */
    #[Route('/web/connections/{id}/databases', requirements: self::ID, methods: ['GET'])]
    public function databases(string $id, Request $request): JsonResponse
    {
        $all = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target): array => $target->introspector()->listDatabases());
        $withSystem = \in_array($request->query->get('system'), ['1', 'true'], true);

        return new JsonResponse(['databases' => array_values(array_map(
            SchemaPresenter::database(...),
            array_filter($all, static fn (DatabaseSummary $database): bool => $withSystem || !$database->system),
        ))]);
    }

    #[Route('/web/connections/{id}/tables', requirements: self::ID, methods: ['GET'])]
    public function tables(string $id, Request $request): JsonResponse
    {
        $tables = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target): array => $target->introspector()->listTables(), DatabaseParam::fromQuery($request));

        return new JsonResponse(['tables' => array_map(SchemaPresenter::summary(...), $tables)]);
    }

    #[Route('/web/connections/{id}/tables/{name}', requirements: self::ID + ['name' => '[^/]+'], methods: ['GET'])]
    public function table(string $id, string $name, Request $request): JsonResponse
    {
        $table = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target) => $target->introspector()->describeTable($name), DatabaseParam::fromQuery($request));

        return new JsonResponse(['table' => SchemaPresenter::table($table)]);
    }
}
