<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditAction;
use App\Audit\AuditedTargetHosts;
use App\Audit\AuditLog;
use App\Connection\ConnectionManager;
use App\Http\Json\ConnectionInput;
use App\Http\Json\JsonInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class ConnectionController
{
    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private ConnectionManager $connections,
        private TargetAccess $targets,
        private AuditedTargetHosts $hosts,
        private WebSession $session,
        private AuditLog $audit,
    ) {
    }

    #[Route('/web/connections', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(['connections' => array_map(
            ConnectionPresenter::summary(...),
            $this->connections->list($this->session->user()),
        )]);
    }

    #[Route('/web/connections', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        [$name, $engine, $parameters] = ConnectionInput::read($input, null);
        $this->hosts->assertAllowed($this->session->user(), $parameters);

        $connection = $this->connections->create($this->session->user(), $this->session->key(), $name, $engine, $parameters);
        $this->audit->record($this->session->auditContext(), AuditAction::ConnectionCreated, subject: $connection->getId(), details: ['name' => $connection->getName(), 'engine' => $engine->value]);

        return new JsonResponse(['connection' => ConnectionPresenter::summary($connection)], Response::HTTP_CREATED);
    }

    #[Route('/web/connections/test', methods: ['POST'])]
    public function probe(Request $request): JsonResponse
    {
        [, $engine, $parameters] = ConnectionInput::read(JsonInput::fromRequest($request), null, nameRequired: false);
        $this->hosts->assertAllowed($this->session->user(), $parameters);

        return new JsonResponse(['ok' => true, 'durationMs' => $this->targets->probe($engine, $parameters)]);
    }

    #[Route('/web/connections/{id}', requirements: self::ID, methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $connection = $this->connections->get($this->session->user(), $uuid);
        $parameters = $this->connections->reveal($this->session->user(), $this->session->key(), $uuid);

        return new JsonResponse(['connection' => ConnectionPresenter::summary($connection), 'parameters' => ConnectionPresenter::parameters($parameters)]);
    }

    #[Route('/web/connections/{id}', requirements: self::ID, methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $user = $this->session->user();
        $key = $this->session->key();
        $current = $this->connections->reveal($user, $key, $uuid);
        [$name, $engine, $parameters] = ConnectionInput::read(JsonInput::fromRequest($request), $current);
        $this->hosts->assertAllowed($user, $parameters);

        $connection = $this->connections->update($user, $key, $uuid, $name, $engine, $parameters);
        $this->audit->record($this->session->auditContext($user), AuditAction::ConnectionUpdated, subject: $uuid, details: ['name' => $connection->getName(), 'engine' => $engine->value]);

        return new JsonResponse(['connection' => ConnectionPresenter::summary($connection)]);
    }

    #[Route('/web/connections/{id}', requirements: self::ID, methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $user = $this->session->user();
        $uuid = Uuid::fromString($id);
        $name = $this->connections->get($user, $uuid)->getName();
        $this->connections->delete($user, $uuid);
        $this->audit->record($this->session->auditContext($user), AuditAction::ConnectionDeleted, subject: $uuid, details: ['name' => $name]);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/web/connections/{id}/test', requirements: self::ID, methods: ['POST'])]
    public function testSaved(string $id): JsonResponse
    {
        $started = hrtime(true);
        $this->targets->withSaved(Uuid::fromString($id), static fn () => null);

        return new JsonResponse(['ok' => true, 'durationMs' => intdiv(hrtime(true) - $started, 1_000_000)]);
    }
}
