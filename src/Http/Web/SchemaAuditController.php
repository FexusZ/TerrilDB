<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Driver\TargetConnection;
use App\Core\Optimizer\Finding;
use App\Core\Optimizer\FindingRule;
use App\Core\Optimizer\OperationsCheck;
use App\Core\Optimizer\SchemaAuditor;
use App\Core\Schema\OperationsSource;
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
        [$findings, $audited, $truncated, $checks] = $this->targets->withSaved(Uuid::fromString($id), function (TargetConnection $target): array {
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

            $findings = $this->auditor->audit($target->engine, $definitions);
            $checks = [];
            // L'exploitation (statistiques, place libre, créateurs) : une source illisible devient un « non vérifié », jamais une erreur.
            if ($introspector instanceof OperationsSource) {
                $upkeep = $introspector->upkeep();
                $definers = $introspector->orphanDefiners();
                $audited = array_fill_keys(array_map(static fn ($d): string => $d->name, $definitions), true);
                // Seules les tables auditées (la borne de 300 tables vaut aussi ici) ; les autres objets n'ont pas de table à eux.
                array_push($findings, ...array_values(array_filter(
                    $this->auditor->operations($target->engine, $upkeep, $definers),
                    static fn (Finding $f): bool => FindingRule::OrphanDefiner === $f->rule || isset($audited[$f->table]),
                )));
                $checks = $this->auditor->operationsChecks($target->engine, $upkeep, $definers);
            }

            return [$findings, \count($definitions), $truncated, $checks];
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
            'checks' => array_map(static fn (OperationsCheck $c): array => [
                'rule' => $c->rule->value,
                'state' => 'unavailable',
                'reason' => $c->reason,
                'detail' => null,
            ], $checks),
        ]);
    }
}
