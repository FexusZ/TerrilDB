<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Http\Web\Security\LoginThrottle;
use App\Security\LoginService;
use App\Security\UserRegistrar;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AccountController
{
    public function __construct(
        private RegistrationPolicy $registration,
        private UserRegistrar $registrar,
        private LoginService $login,
        private LoginThrottle $throttle,
        private WebSession $session,
    ) {
    }

    #[Route('/web/register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $email = $input->string('email');
        $username = $input->string('username');
        $password = $input->string('password');

        if (!$this->registration->isOpen()) {
            return new JsonResponse(['error' => 'registration_closed'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['user' => UserPresenter::present($this->registrar->register($email, $username, $password))], Response::HTTP_CREATED);
    }

    #[Route('/web/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $login = $input->string('login');
        $password = $input->string('password');
        $code = $input->optionalString('code');

        $this->throttle->hit($request->getClientIp(), $login);
        $result = $this->login->authenticate($login, $password, $code);
        $this->throttle->succeeded($login);

        $this->session->open($result->user, $result->key);

        return new JsonResponse(['user' => UserPresenter::present($result->user), 'recoveryCodeUsed' => $result->usedRecoveryCode]);
    }

    #[Route('/web/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return new JsonResponse(['user' => UserPresenter::present($this->session->user())]);
    }
}
