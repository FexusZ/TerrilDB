<?php

declare(strict_types=1);

namespace App\ApiToken;

use App\ApiToken\Exception\ApiTokenPermissionException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Crypto\DerivedKey;
use App\Entity\ApiToken;
use App\Entity\User;

/**
 * Ce qu'un jeton valide donne à la requête en cours : le compte, sa clé de chiffrement (à effacer en fin de
 * requête) et le jeton lui-même, qui dit quelles connexions sont permises et quels droits il porte.
 */
final readonly class ApiTokenAccess
{
    public function __construct(
        public User $user,
        public DerivedKey $key,
        public ApiToken $token,
    ) {
    }

    /**
     * Les diagnostics du serveur (sessions…) ne se lisent qu'avec le droit `diagnostic`, refusé par défaut.
     *
     * @throws ApiTokenPermissionException
     */
    public function requireDiagnostic(): void
    {
        if (!$this->token->getPermissions()->diagnostic) {
            throw ApiTokenPermissionException::create();
        }
    }

    /**
     * Les options d'une exécution d'écriture : lecture-écriture bornée aux droits du jeton. Un jeton sans aucun
     * droit d'écriture n'exécute rien par cette voie (la lecture seule garde sa route, tenue par le moteur).
     *
     * @throws ApiTokenPermissionException
     */
    public function writeOptions(int $timeoutMs, int $maxRows): QueryOptions
    {
        $permissions = $this->token->getPermissions();
        if (!$permissions->canWrite()) {
            throw ApiTokenPermissionException::create();
        }

        return new QueryOptions(QueryMode::ReadWrite, $timeoutMs, $maxRows, $permissions->writes());
    }
}
