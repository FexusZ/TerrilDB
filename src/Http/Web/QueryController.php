<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Core\Driver\TargetConnection;
use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\QueryService;
use App\Http\Json\JsonInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class QueryController
{
    public function __construct(
        private TargetAccess $targets,
        private QueryService $queries,
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

        $result = $this->targets->withSaved(
            Uuid::fromString($id),
            fn (TargetConnection $target) => $this->queries->execute($target, $sql, $options, $confirmed),
        );

        return new JsonResponse([
            'columns' => $result->columns,
            'rows' => $result->rows,
            'rowCount' => $result->rowCount(),
            'truncated' => $result->truncated,
            'affectedRows' => $result->affectedRows,
            'durationMs' => $result->durationMs,
        ]);
    }
}
