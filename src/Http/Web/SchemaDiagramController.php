<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Driver\TargetConnection;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Données du diagramme entité-relation : les tables (pas les vues), leurs colonnes et leurs clés étrangères. Comme
 * l'audit, il décrit chaque table l'une après l'autre, d'où la même borne de 300 tables.
 */
final readonly class SchemaDiagramController
{
    private const MAX_TABLES = 300;

    public function __construct(private TargetAccess $targets)
    {
    }

    #[Route('/web/connections/{id}/diagram', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function diagram(string $id, Request $request): JsonResponse
    {
        [$definitions, $truncated] = $this->targets->withSaved(Uuid::fromString($id), static function (TargetConnection $target): array {
            $introspector = $target->introspector();
            $names = [];
            foreach ($introspector->listTables() as $summary) {
                if (TableKind::Table === $summary->kind) {
                    $names[] = $summary->name;
                }
            }
            $truncated = \count($names) > self::MAX_TABLES;

            $definitions = [];
            foreach (\array_slice($names, 0, self::MAX_TABLES) as $name) {
                $definitions[] = $introspector->describeTable($name);
            }

            return [$definitions, $truncated];
        }, DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        $relations = [];
        foreach ($definitions as $definition) {
            foreach ($definition->foreignKeys as $foreignKey) {
                $relations[] = self::relation($definition, $foreignKey);
            }
        }

        return new JsonResponse([
            'truncated' => $truncated,
            'tables' => array_map(self::table(...), $definitions),
            'relations' => $relations,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function table(TableDefinition $table): array
    {
        $primary = [];
        foreach ($table->indexes as $index) {
            if ($index->primary) {
                $primary = $index->columns;
            }
        }

        return [
            'name' => $table->name,
            'columns' => array_map(static fn (ColumnDefinition $c): array => [
                'name' => $c->name,
                'type' => $c->type,
                'nullable' => $c->nullable,
                'primary' => \in_array($c->name, $primary, true),
            ], $table->columns),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function relation(TableDefinition $table, ForeignKeyDefinition $foreignKey): array
    {
        return [
            'name' => $foreignKey->name,
            'table' => $table->name,
            'columns' => $foreignKey->columns,
            'referencedTable' => $foreignKey->referencedTable,
            'referencedColumns' => $foreignKey->referencedColumns,
        ];
    }
}
