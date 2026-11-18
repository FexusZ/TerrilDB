<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedLint;
use App\Core\Driver\TargetConnection;
use App\Http\Json\JsonInput;
use App\Http\Json\LintPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Le linter de requête : des conseils tirés du texte, croisés avec la structure de l'unique table visée. Rien n'est envoyé à la
 * base (seule la structure d'une table est lue, sous les mêmes règles d'adresses que le reste), rien n'est réécrit, et aucun
 * avertissement n'empêche l'exécution. La demande est journalisée (voir AuditedLint).
 */
final readonly class QueryLintController
{
    public function __construct(
        private TargetAccess $targets,
        private AuditedLint $lint,
        private WebSession $session,
    ) {
    }

    #[Route('/web/connections/{id}/lint', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function lint(string $id, Request $request): JsonResponse
    {
        $sql = JsonInput::fromRequest($request)->string('sql');
        $uuid = Uuid::fromString($id);

        $findings = $this->targets->withSaved($uuid, fn (TargetConnection $target): array => $this->lint->lint($this->session->auditContext(), $uuid, $target, $sql));

        return new JsonResponse(['findings' => LintPresenter::findings($findings)]);
    }
}
