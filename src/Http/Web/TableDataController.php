<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedDataChanges;
use App\Core\Driver\TargetConnection;
use App\Http\Json\DataDraftInput;
use App\Http\Json\DataPlanPresenter;
use App\Http\Json\JsonInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Modification des lignes d'une table. Le client envoie les lignes TELLES QU'IL LES A LUES et ce qu'il en change (`updates`,
 * `inserts`, `deletes`), jamais du SQL : le serveur construit les instructions, les montre (`preview`) puis les applique
 * (`apply`) si chaque ligne est encore retrouvée de façon unique et, pour une suppression, si l'utilisateur a confirmé.
 */
final readonly class TableDataController
{
    private const ID = ['id' => '[0-9a-fA-F-]{36}', 'name' => '[^/]+'];

    public function __construct(
        private TargetAccess $targets,
        private AuditedDataChanges $changes,
        private WebSession $session,
    ) {
    }

    #[Route('/web/connections/{id}/tables/{name}/data/preview', requirements: self::ID, methods: ['POST'])]
    public function preview(string $id, string $name, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $draft = DataDraftInput::from($input);

        $preview = $this->targets->withSaved(
            Uuid::fromString($id),
            fn (TargetConnection $target) => $this->changes->preview($target, $name, $draft),
            $input->optionalString('database'),
            $input->optionalString('schema'),
        );

        return new JsonResponse(DataPlanPresenter::preview($preview));
    }

    #[Route('/web/connections/{id}/tables/{name}/data/apply', requirements: self::ID, methods: ['POST'])]
    public function apply(string $id, string $name, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $draft = DataDraftInput::from($input);
        $confirmed = $input->bool('confirmed', false);
        $uuid = Uuid::fromString($id);

        $plan = $this->targets->withSaved(
            $uuid,
            fn (TargetConnection $target) => $this->changes->apply($this->session->auditContext(), $uuid, $target, $name, $draft, $confirmed),
            $input->optionalString('database'),
            $input->optionalString('schema'),
        );

        return new JsonResponse(DataPlanPresenter::plan($plan));
    }
}
