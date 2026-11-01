<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Audit\AuditOutcome;
use App\Http\Json\JsonInput;
use App\Http\Json\TooManyAttemptsException;
use App\Http\Web\Security\LoginThrottle;
use App\Repository\UserRepository;
use App\Security\Exception\InvalidSecondFactorException;
use App\Security\Exception\LoginFailedException;
use App\Security\Exception\SecondFactorRequiredException;
use App\Security\LoginService;
use App\Security\UserRegistrar;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AccountController
{
    public function __construct(
        private RegistrationPolicy $registration,
        private UserRegistrar $registrar,
        private LoginService $login,
        private LoginThrottle $throttle,
        private WebSession $session,
        private AuditLog $audit,
        private UserRepository $users,
        private RateLimiterFactoryInterface $loginNoticeLimiter,
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

        $user = $this->registrar->register($email, $username, $password);
        $this->audit->record($this->session->auditContext($user), AuditAction::AccountRegistered, subject: $user->getId());

        return new JsonResponse(['user' => UserPresenter::present($user)], Response::HTTP_CREATED);
    }

    #[Route('/web/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $login = $input->string('login');
        $password = $input->string('password');
        $code = $input->optionalString('code');

        try {
            $this->throttle->hit($request->getClientIp(), $login);
        } catch (TooManyAttemptsException $blocked) {
            // Une seule mention par minute : un attaquant qui insiste ne doit pas remplir le journal.
            if ($this->loginNoticeLimiter->create(hash('sha256', mb_strtolower(trim($login))))->consume()->isAccepted()) {
                $this->audit->record($this->session->auditContext($this->users->findByLogin($login)), AuditAction::LoginThrottled, AuditOutcome::Refused);
            }

            throw $blocked;
        }

        try {
            $result = $this->login->authenticate($login, $password, $code);
        } catch (LoginFailedException $failure) {
            // Demander le code n'est pas un échec : c'est l'étape suivante de la connexion.
            if (!$failure instanceof SecondFactorRequiredException) {
                $reason = $failure instanceof InvalidSecondFactorException ? 'invalid_second_factor' : 'invalid_credentials';
                $this->audit->record($this->session->auditContext($this->users->findByLogin($login)), AuditAction::LoginFailure, AuditOutcome::Failed, details: ['reason' => $reason]);
            }

            throw $failure;
        }
        $this->throttle->succeeded($login);

        $this->session->open($result->user, $result->key);
        $context = $this->session->auditContext($result->user);
        $this->audit->record($context, AuditAction::LoginSuccess);
        if ($result->usedRecoveryCode) {
            $this->audit->record($context, AuditAction::RecoveryUsed);
        }

        return new JsonResponse(['user' => UserPresenter::present($result->user), 'recoveryCodeUsed' => $result->usedRecoveryCode]);
    }

    #[Route('/web/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return new JsonResponse(['user' => UserPresenter::present($this->session->user())]);
    }
}
