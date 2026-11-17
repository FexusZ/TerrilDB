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
     * Lignes qu'une lecture rend à un jeton SANS le droit `export` : l'aperçu, pas l'extraction en masse. Au-delà, le résultat est
     * coupé et marqué `truncated`, jamais refusé. Le plafond du serveur (`QueryOptions::MAX_ROWS`) reste celui des jetons avec `export`.
     */
    public const ROWS_WITHOUT_EXPORT = 500;

    /**
     * Le nombre de lignes demandé à `/query` ou `run_query`, ramené à `ROWS_WITHOUT_EXPORT` pour un jeton sans le droit `export`.
     */
    public function readRowLimit(int $requested): int
    {
        return $this->token->getPermissions()->export ? $requested : min($requested, self::ROWS_WITHOUT_EXPORT);
    }

    /**
     * La liste des bases et des schémas du serveur ne se lit qu'avec le droit `readSchema`, refusé par défaut.
     *
     * @throws ApiTokenPermissionException
     */
    public function requireReadSchema(): void
    {
        if (!$this->token->getPermissions()->readSchema) {
            throw ApiTokenPermissionException::create();
        }
    }

    /**
     * L'export en masse (CSV, JSON) ne se fait qu'avec le droit `export`, refusé par défaut.
     *
     * @throws ApiTokenPermissionException
     */
    public function requireExport(): void
    {
        if (!$this->token->getPermissions()->export) {
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
