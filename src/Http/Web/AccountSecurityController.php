<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Audit\AuditOutcome;
use App\Http\Json\JsonInput;
use App\Http\Web\Security\LoginThrottle;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\PasswordChanger;
use App\Security\TotpEnrollment;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mot de passe, second facteur et codes de récupération. Chaque action qui vérifie le mot de passe compte dans
 * le même plafond de tentatives que la connexion : une session volée ne doit pas servir à le deviner.
 */
final readonly class AccountSecurityController
{
    public function __construct(
        private WebSession $session,
        private PasswordChanger $passwordChanger,
        private TotpEnrollment $totp,
        private LoginThrottle $throttle,
        private AuditLog $audit,
    ) {
    }

    #[Route('/web/account/password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $current = $input->string('current');
        $new = $input->string('new');
        $user = $this->session->user();

        $this->throttlePasswordChecks($request);
        try {
            $key = $this->passwordChanger->change($user, $current, $new);
        } catch (InvalidCredentialsException $failure) {
            $this->audit->record($this->session->auditContext($user), AuditAction::PasswordChangeFailed, AuditOutcome::Failed, details: ['reason' => 'invalid_credentials']);

            throw $failure;
        }

        // Le hash change, donc la session courante n'est plus reconnue : on la rouvre avec la nouvelle clé.
        // Les autres sessions, elles, sont perdues.
        $this->session->open($user, $key);
        $this->audit->record($this->session->auditContext($user), AuditAction::PasswordChanged);

        return new JsonResponse(['user' => UserPresenter::present($user)]);
    }

    #[Route('/web/account/totp/begin', methods: ['POST'])]
    public function beginTotp(): JsonResponse
    {
        $pending = $this->totp->begin($this->session->user());

        return new JsonResponse(['secret' => $pending->secret, 'uri' => $pending->provisioningUri]);
    }

    #[Route('/web/account/totp/confirm', methods: ['POST'])]
    public function confirmTotp(Request $request): JsonResponse
    {
        $input = JsonInput::fromRequest($request);
        $secret = $input->string('secret');
        $code = $input->string('code');

        $user = $this->session->user();
        $codes = $this->totp->confirm($user, $this->session->key(), $secret, $code);
        $this->audit->record($this->session->auditContext($user), AuditAction::TotpEnabled);

        return new JsonResponse(['recoveryCodes' => $codes->codes]);
    }

    #[Route('/web/account/totp/disable', methods: ['POST'])]
    public function disableTotp(Request $request): Response
    {
        $password = JsonInput::fromRequest($request)->string('password');

        $this->throttlePasswordChecks($request);
        $user = $this->session->user();
        try {
            $this->totp->disable($user, $password);
        } catch (InvalidCredentialsException $failure) {
            $this->audit->record($this->session->auditContext($user), AuditAction::TotpDisabled, AuditOutcome::Failed, details: ['reason' => 'invalid_credentials']);

            throw $failure;
        }
        $this->audit->record($this->session->auditContext($user), AuditAction::TotpDisabled);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/web/account/recovery-codes', methods: ['POST'])]
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $password = JsonInput::fromRequest($request)->string('password');

        $this->throttlePasswordChecks($request);
        $user = $this->session->user();
        try {
            $codes = $this->totp->regenerateRecoveryCodes($user, $password);
        } catch (InvalidCredentialsException $failure) {
            $this->audit->record($this->session->auditContext($user), AuditAction::RecoveryRegenerated, AuditOutcome::Failed, details: ['reason' => 'invalid_credentials']);

            throw $failure;
        }
        $this->audit->record($this->session->auditContext($user), AuditAction::RecoveryRegenerated);

        return new JsonResponse(['recoveryCodes' => $codes->codes]);
    }

    private function throttlePasswordChecks(Request $request): void
    {
        $this->throttle->hit($request->getClientIp(), 'password-check:'.$this->session->user()->getId()->toRfc4122());
    }
}
