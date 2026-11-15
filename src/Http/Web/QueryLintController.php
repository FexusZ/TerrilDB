<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Audit\AuditOutcome;
use App\Core\Driver\TargetConnection;
use App\Core\Optimizer\LintFinding;
use App\Core\Optimizer\QueryLinter;
use App\Http\Json\JsonInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Le linter de requête : des conseils tirés du texte seul. Rien n'est envoyé à la base (la cible n'est ouverte que pour
 * connaître son moteur, sous les mêmes règles d'adresses que le reste), rien n'est réécrit, et aucun avertissement
 * n'empêche l'exécution. La demande est journalisée avec l'instruction, comme celle d'un plan.
 */
final readonly class QueryLintController
{
    public function __construct(
        private TargetAccess $targets,
        private QueryLinter $linter,
        private AuditLog $audit,
        private WebSession $session,
    ) {
    }

    #[Route('/web/connections/{id}/lint', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function lint(string $id, Request $request): JsonResponse
    {
        $sql = JsonInput::fromRequest($request)->string('sql');
        $uuid = Uuid::fromString($id);

        [$engine, $findings] = $this->targets->withSaved($uuid, fn (TargetConnection $target): array => [$target->engine, $this->linter->lint($target->engine, $sql)]);

        $this->audit->record($this->session->auditContext(), AuditAction::QueryLinted, AuditOutcome::Success, $uuid, [
            'sql' => $sql,
            'engine' => $engine->value,
            'findings' => \count($findings),
            'rules' => array_map(static fn (LintFinding $f): string => $f->rule->value, $findings),
        ]);

        return new JsonResponse(['findings' => array_map(static fn (LintFinding $f): array => [
            'rule' => $f->rule->value,
            'severity' => $f->severity->value,
            'message' => $f->message,
            'suggestion' => $f->suggestion,
            'columns' => $f->columns,
        ], $findings)]);
    }
}
