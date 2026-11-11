<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Driver\TargetConnection;
use App\Core\Optimizer\Finding;
use App\Core\Optimizer\SchemaAuditor;
use App\Core\Schema\TableKind;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Audit du schéma d'une connexion. Il décrit chaque table (quelques requêtes d'introspection par table), d'où une borne :
 * au-delà de 300 tables, l'audit s'arrête et le dit.
 */
final readonly class SchemaAuditController
{
    private const MAX_TABLES = 300;

    public function __construct(
        private TargetAccess $targets,
        private SchemaAuditor $auditor,
    ) {
    }

    #[Route('/web/connections/{id}/audit', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function audit(string $id, Request $request): JsonResponse
    {
        [$findings, $audited, $truncated] = $this->targets->withSaved(Uuid::fromString($id), function (TargetConnection $target): array {
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

            return [$this->auditor->audit($target->engine, $definitions, $introspector->autoCounters()), \count($definitions), $truncated];
        }, DatabaseParam::fromQuery($request), DatabaseParam::schemaFromQuery($request));

        return new JsonResponse([
            'tablesAudited' => $audited,
            'truncated' => $truncated,
            'findings' => array_map(static fn (Finding $f): array => [
                'rule' => $f->rule->value,
                'severity' => $f->severity->value,
                'table' => $f->table,
                'columns' => $f->columns,
                'index' => $f->index,
                'coveredBy' => $f->coveredBy,
                'suggestion' => $f->suggestion,
                'detail' => $f->detail,
            ], $findings),
        ]);
    }
}
