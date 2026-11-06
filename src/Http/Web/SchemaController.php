<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Driver\TargetConnection;
use App\Core\Schema\DatabaseSummary;
use App\Core\Schema\SchemaSummary;
use App\Core\Schema\TablePage;
use App\Core\Schema\TablePageRequest;
use App\Http\Json\BadRequestException;
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

        return new JsonResponse([
            // La base que la connexion nomme (null : elle n'en nomme aucune, l'interface en propose une à choisir).
            'default' => $this->targets->defaultDatabase(Uuid::fromString($id)),
            'databases' => array_values(array_map(
                SchemaPresenter::database(...),
                array_filter($all, static fn (DatabaseSummary $database): bool => $withSystem || !$database->system),
            )),
        ]);
    }

    /**
     * Les schémas de la base (PostgreSQL) ; ceux du moteur sont masqués sauf `?system=1`.
     */
    #[Route('/web/connections/{id}/schemas', requirements: self::ID, methods: ['GET'])]
    public function schemas(string $id, Request $request): JsonResponse
    {
        $all = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target): array => $target->introspector()->listSchemas(), DatabaseParam::fromQuery($request));
        $withSystem = \in_array($request->query->get('system'), ['1', 'true'], true);

        return new JsonResponse(['schemas' => array_values(array_map(
            SchemaPresenter::schema(...),
            array_filter($all, static fn (SchemaSummary $schema): bool => $withSystem || !$schema->system),
        ))]);
    }

    #[Route('/web/connections/{id}/tables', requirements: self::ID, methods: ['GET'])]
    public function tables(string $id, Request $request): JsonResponse
    {
        // Sans `limit`, `cursor` ni `q`, la liste complète (l'éditeur s'en sert pour la complétion) ; avec l'un d'eux, une page.
        if (null !== $request->query->get('limit') || null !== $request->query->get('cursor') || null !== $request->query->get('q')) {
            $page = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target): TablePage => $target->introspector()->listTablesPage(self::pageRequest($request)), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

            return new JsonResponse(['tables' => array_map(SchemaPresenter::summary(...), $page->tables), 'nextCursor' => $page->nextCursor, 'total' => $page->total]);
        }

        $tables = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target): array => $target->introspector()->listTables(), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(['tables' => array_map(SchemaPresenter::summary(...), $tables)]);
    }

    #[Route('/web/connections/{id}/tables/{name}', requirements: self::ID + ['name' => '[^/]+'], methods: ['GET'])]
    public function table(string $id, string $name, Request $request): JsonResponse
    {
        $table = $this->targets->withSaved(Uuid::fromString($id), static fn (TargetConnection $target) => $target->introspector()->describeTable($name), DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse(['table' => SchemaPresenter::table($table)]);
    }

    private static function pageRequest(Request $request): TablePageRequest
    {
        $value = filter_var($request->query->get('limit', (string) TablePageRequest::DEFAULT_LIMIT), \FILTER_VALIDATE_INT);
        if (!\is_int($value) || $value < 1 || $value > TablePageRequest::MAX_LIMIT) {
            throw new BadRequestException(\sprintf('The limit must be an integer between 1 and %d.', TablePageRequest::MAX_LIMIT));
        }
        $cursor = $request->query->get('cursor');
        $search = $request->query->get('q');

        return new TablePageRequest($value, \is_string($cursor) && '' !== $cursor ? $cursor : null, \is_string($search) ? $search : null);
    }
}
