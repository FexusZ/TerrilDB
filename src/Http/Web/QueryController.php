<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditedQueries;
use App\Core\Driver\TargetConnection;
use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Http\Json\JsonInput;
use App\Http\Json\ResultPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class QueryController
{
    public function __construct(
        private TargetAccess $targets,
        private AuditedQueries $queries,
        private WebSession $session,
    ) {
    }

    #[Route('/web/connections/{id}/query', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function execute(string $id, Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $sql = $input->string('sql');
        $confirmed = $input->bool('confirmed', false);
        $defaults = new QueryOptions();
        $options = new QueryOptions(
            QueryMode::tryFrom($input->optionalString('mode') ?? QueryMode::ReadOnly->value) ?? throw new InvalidQueryOptionsException('The mode is not supported.'),
            $input->optionalInt('timeoutMs', $defaults->timeoutMs) ?? $defaults->timeoutMs,
            $input->optionalInt('maxRows', $defaults->maxRows) ?? $defaults->maxRows,
        );

        $uuid = Uuid::fromString($id);
        $result = $this->targets->withSaved(
            $uuid,
            fn (TargetConnection $target) => $this->queries->execute($this->session->auditContext(), $uuid, $target, $sql, $options, $confirmed),
            $input->optionalString('database'),
        );

        return new JsonResponse(ResultPresenter::present($result));
    }
}
