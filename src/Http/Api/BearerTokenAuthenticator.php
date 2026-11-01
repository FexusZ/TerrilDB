<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\ApiToken\ApiTokenService;
use App\ApiToken\Exception\InvalidApiTokenException;
use App\Http\Web\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authentifie une requête de l'API par `Authorization: Bearer <jeton>`, et seulement ainsi : ni cookie de session,
 * ni autre schéma. Le jeton valide donne le compte et sa clé de chiffrement pour cette requête (ApiRequestAccess) ;
 * tout échec rend la même réponse, qu'il s'agisse d'un jeton absent, inconnu, faux, expiré ou révoqué.
 */
final class BearerTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly ApiThrottle $throttle,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $this->throttle->assertNotBlocked($request->getClientIp());

        if (1 !== preg_match('/^Bearer (\S+)$/D', (string) $request->headers->get('Authorization'), $matches)) {
            throw new BadCredentialsException();
        }

        try {
            $access = $this->tokens->authenticate($matches[1]);
        } catch (InvalidApiTokenException) {
            throw new BadCredentialsException();
        }

        try {
            $this->throttle->used($access->token->getId());
        } catch (\Throwable $e) {
            $access->key->wipe();

            throw $e;
        }

        $request->attributes->set(ApiRequestAccess::ATTRIBUTE, $access);

        return new SelfValidatingPassport(new UserBadge(
            $access->user->getId()->toRfc4122(),
            static fn (): SecurityUser => SecurityUser::fromUser($access->user),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->throttle->failed($request->getClientIp());

        return self::challenge();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::challenge();
    }

    private static function challenge(): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_token'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
    }
}
