<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedSchemaChanges;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\ChangePlan;
use App\Http\Json\ChangePlanPresenter;
use App\Http\Json\JsonInput;
use App\Http\Json\SchemaPresenter;
use App\Http\Json\TableDraftInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Modification de la structure d'une table : colonnes, index, clés, nom, suppression. Le client envoie la table TELLE QU'ON LA
 * VEUT (`draft`, ou `drop: true`), jamais du SQL : le serveur relit la table réelle, déduit les instructions, les montre
 * (`preview`) puis les applique (`apply`) si l'empreinte (`fingerprint`) est encore la bonne et, pour ce qui détruit, si
 * l'utilisateur a confirmé.
 */
final readonly class TableStructureController
{
    private const ID = ['id' => '[0-9a-fA-F-]{36}', 'name' => '[^/]+'];

    public function __construct(
        private TargetAccess $targets,
        private AuditedSchemaChanges $changes,
        private WebSession $session,
    ) {
    }

    #[Route('/web/connections/{id}/tables/{name}/structure/preview', requirements: self::ID, methods: ['POST'])]
    public function preview(string $id, string $name, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $draft = $input->bool('drop', false) ? null : TableDraftInput::from($input->object('draft'));
        $fingerprint = $input->optionalString('fingerprint');

        $preview = $this->targets->withSaved(
            Uuid::fromString($id),
            fn (TargetConnection $target) => $this->changes->preview($target, $name, $draft, $fingerprint),
            $input->optionalString('database'),
            $input->optionalString('schema'),
        );

        return new JsonResponse(ChangePlanPresenter::plan($preview->plan) + ['fingerprint' => $preview->fingerprint, 'stale' => $preview->stale]);
    }

    #[Route('/web/connections/{id}/tables/{name}/structure/apply', requirements: self::ID, methods: ['POST'])]
    public function apply(string $id, string $name, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $draft = $input->bool('drop', false) ? null : TableDraftInput::from($input->object('draft'));
        $fingerprint = $input->string('fingerprint');
        $confirmed = $input->bool('confirmed', false);
        $uuid = Uuid::fromString($id);

        /** @var array{ChangePlan, ?string, ?\App\Core\Schema\TableDefinition} $result */
        $result = $this->targets->withSaved(
            $uuid,
            function (TargetConnection $target) use ($uuid, $name, $draft, $fingerprint, $confirmed): array {
                [$plan, $after] = $this->changes->apply($this->session->auditContext(), $uuid, $target, $name, $draft, $fingerprint, $confirmed);

                return [$plan, $after, null === $draft || null === $after ? null : $target->introspector()->describeTable($draft->name)];
            },
            $input->optionalString('database'),
            $input->optionalString('schema'),
        );
        [$plan, , $table] = $result;

        return new JsonResponse(ChangePlanPresenter::plan($plan) + ['table' => null === $table ? null : SchemaPresenter::table($table)]);
    }
}
