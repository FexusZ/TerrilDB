<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Audit\AuditActor;
use App\Audit\AuditedLint;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Core\Driver\TargetConnection;
use App\Http\Json\JsonInput;
use App\Http\Json\LintPresenter;
use App\Http\Json\TargetSession;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Le linter de requête pour les jetons : mêmes conseils et même audit que l'interface web (AuditedLint), sans droit particulier,
 * car rien n'est exécuté ni écrit. Le texte reçu n'est jamais envoyé à la base ; seule la structure de la table visée est lue.
 */
final readonly class ApiLintController
{
    public function __construct(
        private ApiRequestAccess $request,
        private TargetSession $targets,
        private AuditedLint $lint,
    ) {
    }

    #[Route('/api/v1/connections/{id}/lint', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function lint(string $id, Request $request): JsonResponse
    {
        $sql = JsonInput::fromRequest($request)->string('sql');
        $connectionId = Uuid::fromString($id);
        $access = $this->request->get();
        if (!$access->token->allows($connectionId)) {
            throw ConnectionNotFoundException::create();
        }

        $findings = $this->targets->with($access->user, $access->key, $connectionId, fn (TargetConnection $target): array => $this->lint->lint($this->request->auditContext(AuditActor::Api), $connectionId, $target, $sql));

        return new JsonResponse(['findings' => LintPresenter::findings($findings)]);
    }
}
