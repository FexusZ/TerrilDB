<?php

declare(strict_types=1);

namespace App\ApiToken;

use App\ApiToken\Exception\ApiTokenPermissionException;
use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditLog;
use App\Connection\ConnectionManager;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Entity\DatabaseConnection;
use App\Repository\ApiTokenRepository;

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
        private AuditLog $audit,
    ) {
    }

    /**
     * @throws ApiTokenPermissionException
     */
    public function create(ApiTokenAccess $access, AuditContext $context, string $name, Engine $engine, ConnectionParameters $parameters): DatabaseConnection
    {
        if (!$access->token->getPermissions()->createConnections) {
            throw ApiTokenPermissionException::create();
        }

        $connection = $this->connections->create($access->user, $access->key, $name, $engine, $parameters);
        $access->token->grantConnection($connection->getId());
        $this->tokens->save($access->token);
        $this->audit->record($context, AuditAction::ConnectionCreated, subject: $connection->getId(), details: ['name' => $connection->getName(), 'engine' => $engine->value]);

        return $connection;
    }
}
