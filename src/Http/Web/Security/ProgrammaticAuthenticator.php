<?php

declare(strict_types=1);

namespace App\Http\Web\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * N'authentifie jamais une requête de lui-même : la connexion est faite par AccountController, qui dérive aussi
 * la clé de chiffrement. Cet authentificateur sert uniquement à ouvrir la session Symfony (Security::login).
 */
final class ProgrammaticAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): ?bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        throw new \LogicException('Login goes through AccountController.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
