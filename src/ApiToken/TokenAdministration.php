<?php

declare(strict_types=1);

namespace App\ApiToken;

use App\ApiToken\Exception\ApiTokenNotFoundException;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Invitation\Exception\NotAnAdministratorException;
use App\Repository\ApiTokenRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Les jetons d'API de tous les comptes vus par un administrateur : liste et révocation. Un jeton ne se lit jamais
 * au-delà de ses métadonnées (ni secret, ni empreinte, ni clé enveloppée) ; ce service ne les expose pas.
 */
final readonly class TokenAdministration
{
    public function __construct(private ApiTokenRepository $tokens)
    {
    }

    /**
     * @return list<ApiToken>
     */
    public function list(User $admin): array
    {
        $this->assertAdmin($admin);

        return $this->tokens->findAllWithOwners();
    }

    /**
     * Supprime le jeton, définitivement. Un jeton inconnu est « introuvable », sans dire à qui il serait.
     */
    public function revoke(User $admin, Uuid $id): ApiToken
    {
        $this->assertAdmin($admin);
        $token = $this->tokens->find($id) ?? throw ApiTokenNotFoundException::create();
        $this->tokens->remove($token);

        return $token;
    }

    private function assertAdmin(User $user): void
    {
        if (!$user->isAdmin()) {
            throw NotAnAdministratorException::create();
        }
    }
}
