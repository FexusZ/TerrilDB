<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Connection\ConnectionManager;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
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
        private WebSession $session,
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
        [$name, $engine, $parameters] = $this->read($input, null);

        $connection = $this->connections->create($this->session->user(), $this->session->key(), $name, $engine, $parameters);

        return new JsonResponse(['connection' => ConnectionPresenter::summary($connection)], Response::HTTP_CREATED);
    }

    #[Route('/web/connections/test', methods: ['POST'])]
    public function probe(Request $request): JsonResponse
    {
        [, $engine, $parameters] = $this->read(JsonInput::fromRequest($request), null, nameRequired: false);

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
        [$name, $engine, $parameters] = $this->read(JsonInput::fromRequest($request), $current);

        $connection = $this->connections->update($user, $key, $uuid, $name, $engine, $parameters);

        return new JsonResponse(['connection' => ConnectionPresenter::summary($connection)]);
    }

    #[Route('/web/connections/{id}', requirements: self::ID, methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $this->connections->delete($this->session->user(), Uuid::fromString($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/web/connections/{id}/test', requirements: self::ID, methods: ['POST'])]
    public function testSaved(string $id): JsonResponse
    {
        $started = hrtime(true);
        $this->targets->withSaved(Uuid::fromString($id), static fn () => null);

        return new JsonResponse(['ok' => true, 'durationMs' => intdiv(hrtime(true) - $started, 1_000_000)]);
    }

    /**
     * @param ConnectionParameters|null $current les paramètres déjà enregistrés : un mot de passe absent les conserve
     *
     * @return array{string, Engine, ConnectionParameters}
     */
    private function read(JsonInput $input, ?ConnectionParameters $current, bool $nameRequired = true): array
    {
        $engine = $input->engine('engine');
        $password = $input->optionalString('password') ?? $current?->password() ?? '';

        return [
            $nameRequired ? $input->string('name') : ($input->optionalString('name') ?? ''),
            $engine,
            new ConnectionParameters(
                $input->string('host'),
                $input->optionalInt('port') ?? $engine->defaultPort(),
                $input->string('database'),
                $input->string('username'),
                $password,
            ),
        ];
    }
}
