<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedScripts;
use App\Core\Driver\TargetConnection;
use App\Core\Query\Script\ScriptOptions;
use App\Core\Query\Script\ScriptService;
use App\Core\Query\WritePermissions;
use App\Http\Json\JsonInput;
use App\Http\Json\ScriptPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Un script SQL : l'aperçu juge chaque instruction sans rien exécuter ; l'exécution part dans la transaction du moteur,
 * s'arrête à la première erreur et rend le compte rendu instruction par instruction. Une session web a tous les droits
 * d'écriture (comme `mode: read_write` de la console) ; refus, confirmation unique et audit sont ceux du cœur.
 */
final readonly class ScriptController
{
    public function __construct(
        private TargetAccess $targets,
        private ScriptService $scripts,
        private AuditedScripts $audited,
        private WebSession $session,
    ) {
    }

    #[Route('/web/connections/{id}/script/preview', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function preview(string $id, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $script = $input->string('script');

        $preview = $this->targets->withSaved(
            Uuid::fromString($id),
            fn (TargetConnection $target) => $this->scripts->preview($script, $target->engine, WritePermissions::all()),
            $input->optionalString('database'),
            $input->optionalString('schema'),
        );

        return new JsonResponse(ScriptPresenter::preview($preview));
    }

    #[Route('/web/connections/{id}/script/execute', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function execute(string $id, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $script = $input->string('script');
        $options = new ScriptOptions(
            WritePermissions::all(),
            $input->optionalInt('timeoutMs', ScriptOptions::DEFAULT_TIMEOUT_MS) ?? ScriptOptions::DEFAULT_TIMEOUT_MS,
            $input->bool('dryRun', false),
        );
        $confirmed = $input->bool('confirmed', false);

        $uuid = Uuid::fromString($id);
        $run = $this->targets->withSaved(
            $uuid,
            fn (TargetConnection $target) => $this->audited->execute($this->session->auditContext(), $uuid, $target, $script, $options, $confirmed),
            $input->optionalString('database'),
            $input->optionalString('schema'),
        );

        return new JsonResponse(ScriptPresenter::result($run->result, $run->executionId->toRfc4122()));
    }
}
