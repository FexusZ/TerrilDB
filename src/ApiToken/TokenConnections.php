<?php

declare(strict_types=1);

namespace App\ApiToken;

use App\ApiToken\Exception\ApiTokenPermissionException;
use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditedTargetHosts;
use App\Audit\AuditLog;
use App\Connection\ConnectionManager;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Entity\DatabaseConnection;
use App\Http\Json\TooManyAttemptsException;
use App\Repository\ApiTokenRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Un jeton qui en a le droit enregistre une nouvelle connexion dans le compte, chiffrée par la clé que le jeton
 * porte, comme le ferait l'interface web. La connexion rejoint aussitôt la liste du jeton (sans quoi il ne pourrait
 * pas s'en servir) ; les autres jetons ne la voient qu'à leur propre actualisation.
 */
final readonly class TokenConnections
{
    public function __construct(
        private ConnectionManager $connections,
        private ApiTokenRepository $tokens,
        private AuditedTargetHosts $hosts,
        #[Autowire(service: 'limiter.api_connection_creation')] private RateLimiterFactoryInterface $creationLimiter,
        private AuditLog $audit,
    ) {
    }

    /**
     * @throws ApiTokenPermissionException
     * @throws TooManyAttemptsException    plus de 20 tentatives dans l'heure pour ce jeton
     */
    public function create(ApiTokenAccess $access, AuditContext $context, string $name, Engine $engine, ConnectionParameters $parameters): DatabaseConnection
    {
        if (!$access->token->getPermissions()->createConnections) {
            throw ApiTokenPermissionException::create();
        }

        // Chaque tentative compte, aboutie ou refusée : sonder des adresses use le même budget qu'en créer.
        $state = $this->creationLimiter->create($access->token->getId()->toRfc4122())->consume();
        if (!$state->isAccepted()) {
            throw new TooManyAttemptsException(max(1, $state->getRetryAfter()->getTimestamp() - time()));
        }

        $this->hosts->assertAllowed($access->user, $parameters);
        $connection = $this->connections->create($access->user, $access->key, $name, $engine, $parameters);
        $access->token->grantConnection($connection->getId());
        $this->tokens->save($access->token);
        $this->audit->record($context, AuditAction::ConnectionCreated, subject: $connection->getId(), details: ['name' => $connection->getName(), 'engine' => $engine->value]);

        return $connection;
    }
}
