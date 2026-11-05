<?php

declare(strict_types=1);

namespace App\ApiToken;

use App\ApiToken\Exception\ApiTokenNotFoundException;
use App\ApiToken\Exception\InvalidApiTokenException;
use App\Connection\ConnectionManager;
use App\Crypto\DerivedKey;
use App\Crypto\Exception\CryptoException;
use App\Crypto\SessionKeyWrapper;
use App\Entity\ApiToken;
use App\Entity\DatabaseConnection;
use App\Entity\Exception\InvalidApiTokenDefinitionException;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Émission et vérification des jetons d'API. Le jeton emporte une copie de la clé de l'utilisateur enveloppée par
 * son propre secret : sans le jeton, le serveur ne peut pas la rouvrir, et c'est ce qui permet à une requête
 * d'API (sans mot de passe) de déchiffrer les connexions que le jeton autorise. Voir l'issue sur la clé des jetons.
 */
final readonly class ApiTokenService
{
    public const MAX_LIFETIME_DAYS = 365;

    public function __construct(
        private SessionKeyWrapper $wrapper,
        private ApiTokenRepository $tokens,
        private ConnectionManager $connections,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<Uuid> $connectionIds  connexions que le jeton pourra ouvrir (au moins une, toutes à l'utilisateur) ;
     *                                   ignorées si $allConnections : le jeton prend alors toutes celles du compte
     * @param bool       $allConnections jeton « toutes mes connexions », dont la liste s'actualise ensuite à la demande
     */
    public function issue(User $owner, DerivedKey $key, string $name, array $connectionIds, int $lifetimeDays, bool $allConnections = false): IssuedApiToken
    {
        if ($lifetimeDays < 1 || $lifetimeDays > self::MAX_LIFETIME_DAYS) {
            throw new InvalidApiTokenDefinitionException('The token lifetime must be between 1 and 365 days.');
        }
        if ($allConnections) {
            $connectionIds = $this->ownedConnectionIds($owner);
        }
        if ([] === $connectionIds) {
            throw new InvalidApiTokenDefinitionException('A token needs at least one connection.');
        }
        foreach ($connectionIds as $connectionId) {
            $this->connections->get($owner, $connectionId);
        }

        $id = Uuid::v7();
        $secret = ApiTokenSecret::generate($id);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $token = new ApiToken(
            $id,
            $owner,
            $name,
            $secret->verifier(),
            $this->wrapper->wrap($key, $secret->secret(), $id->toRfc4122()),
            array_map(static fn (Uuid $connectionId): string => $connectionId->toRfc4122(), $connectionIds),
            $now,
            $now->modify(\sprintf('+%d days', $lifetimeDays)),
            $allConnections,
        );
        $this->tokens->save($token);

        return new IssuedApiToken($secret->toString(), $token);
    }

    /**
     * @throws InvalidApiTokenException jeton mal formé, inconnu, au secret erroné, expiré, révoqué ou altéré
     */
    public function authenticate(#[\SensitiveParameter] string $presented): ApiTokenAccess
    {
        $secret = ApiTokenSecret::parse($presented);
        $token = $this->tokens->find($secret->id);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if (null === $token || !$token->matches($secret->verifier()) || $token->isExpired($now)) {
            throw InvalidApiTokenException::create();
        }

        try {
            $key = $this->wrapper->unwrap($token->getWrappedKey(), $secret->secret(), $token->getId()->toRfc4122());
        } catch (CryptoException) {
            throw InvalidApiTokenException::create();
        }

        if ($token->recordUse($now)) {
            $this->tokens->save($token);
        }

        return new ApiTokenAccess($token->getOwner(), $key, $token);
    }

    /**
     * Met la liste d'un jeton « toutes mes connexions » à jour : les connexions ajoutées depuis y entrent, celles
     * supprimées en sortent. Le texte du jeton ne change pas.
     */
    public function refresh(User $owner, Uuid $id): ApiToken
    {
        $token = $this->tokens->findOwned($owner, $id) ?? throw ApiTokenNotFoundException::create();
        $connectionIds = $this->ownedConnectionIds($owner);
        if ([] === $connectionIds) {
            throw new InvalidApiTokenDefinitionException('A token needs at least one connection.');
        }
        $token->refreshConnections(array_map(static fn (Uuid $connectionId): string => $connectionId->toRfc4122(), $connectionIds));
        $this->tokens->save($token);

        return $token;
    }

    public function revoke(User $owner, Uuid $id): void
    {
        $token = $this->tokens->findOwned($owner, $id) ?? throw ApiTokenNotFoundException::create();
        $this->tokens->remove($token);
    }

    /**
     * Supprime tous les jetons du compte, sans valider : l'appelant groupe l'écriture avec la sienne (changement
     * de mot de passe). Un jeton ne survit pas à un changement de clé.
     */
    public function revokeAll(User $owner): void
    {
        foreach ($this->tokens->findAllOwnedBy($owner) as $token) {
            $this->tokens->remove($token, flush: false);
        }
    }

    /**
     * @return list<ApiToken>
     */
    public function list(User $owner): array
    {
        return $this->tokens->findAllOwnedBy($owner);
    }

    /**
     * @return list<Uuid>
     */
    private function ownedConnectionIds(User $owner): array
    {
        return array_map(static fn (DatabaseConnection $connection): Uuid => $connection->getId(), $this->connections->list($owner));
    }
}
