<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Http\Web\Security\LoginThrottle;
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
        $key = $this->passwordChanger->change($user, $current, $new);

        // Le hash change, donc la session courante n'est plus reconnue : on la rouvre avec la nouvelle clé.
        // Les autres sessions, elles, sont perdues.
        $this->session->open($user, $key);

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

        $codes = $this->totp->confirm($this->session->user(), $this->session->key(), $secret, $code);

        return new JsonResponse(['recoveryCodes' => $codes->codes]);
    }

    #[Route('/web/account/totp/disable', methods: ['POST'])]
    public function disableTotp(Request $request): Response
    {
        $password = JsonInput::fromRequest($request)->string('password');

        $this->throttlePasswordChecks($request);
        $this->totp->disable($this->session->user(), $password);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/web/account/recovery-codes', methods: ['POST'])]
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $password = JsonInput::fromRequest($request)->string('password');

        $this->throttlePasswordChecks($request);
        $codes = $this->totp->regenerateRecoveryCodes($this->session->user(), $password);

        return new JsonResponse(['recoveryCodes' => $codes->codes]);
    }

    private function throttlePasswordChecks(Request $request): void
    {
        $this->throttle->hit($request->getClientIp(), 'password-check:'.$this->session->user()->getId()->toRfc4122());
    }
}
