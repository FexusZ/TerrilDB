<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Audit\AuditOutcome;
use App\Entity\User;
use App\Http\Json\JsonInput;
use App\Http\Web\Security\LoginThrottle;
use App\Repository\UserRepository;
use App\Security\AuthPasswordHasher;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\Exception\InvalidRecoveryException;
use App\Security\RecoveryPhraseService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Phrase de récupération : la créer ou la retirer (session et mot de passe exigés), et s'en servir pour choisir un
 * nouveau mot de passe sans être connecté. Cette dernière route est publique donc plafonnée comme la connexion, répond
 * toujours pareil à un échec, et n'ouvre aucune session : le second facteur reste exigé à la connexion qui suit.
 */
final readonly class RecoveryController
{
    public function __construct(
        private RecoveryPhraseService $recovery,
        private AuthPasswordHasher $hasher,
        private WebSession $session,
        private LoginThrottle $throttle,
        private UserRepository $users,
        private AuditLog $audit,
    ) {
    }

    #[Route('/web/account/recovery-phrase', methods: ['POST'])]
    public function enable(Request $request): JsonResponse
    {
        $user = $this->confirmedUser($request);
        $phrase = $this->recovery->enable($user, $this->session->key());
        $this->audit->record($this->session->auditContext($user), AuditAction::PhraseCreated);

        return new JsonResponse(['phrase' => $phrase->toString()], Response::HTTP_CREATED);
    }

    #[Route('/web/account/recovery-phrase/disable', methods: ['POST'])]
    public function disable(Request $request): Response
    {
        $user = $this->confirmedUser($request);
        $this->recovery->disable($user);
        $this->audit->record($this->session->auditContext($user), AuditAction::PhraseRemoved);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/web/recover', methods: ['POST'])]
    public function recover(Request $request): Response
    {
        $input = JsonInput::fromRequest($request);
        $login = $input->string('login');
        $phrase = $input->string('phrase');
        $newPassword = $input->string('newPassword');

        $this->throttle->hit($request->getClientIp(), $login);
        $user = $this->users->findByLogin($login);

        try {
            if (null === $user) {
                throw InvalidRecoveryException::create();
            }
            $key = $this->recovery->reset($user, $phrase, $newPassword);
        } catch (InvalidRecoveryException $failure) {
            $this->audit->record($this->session->auditContext($user), AuditAction::PhraseFailed, AuditOutcome::Failed);

            throw $failure;
        }
        // La clé neuve n'ouvre aucune session : l'utilisateur se connecte avec son nouveau mot de passe (et son code).
        $key->wipe();
        $this->throttle->succeeded($login);
        $this->audit->record($this->session->auditContext($user), AuditAction::PhraseUsed);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function confirmedUser(Request $request): User
    {
        $password = JsonInput::fromRequest($request)->string('password');
        $user = $this->session->user();
        $this->throttle->hit($request->getClientIp(), 'password-check:'.$user->getId()->toRfc4122());
        if (!$this->hasher->verify($user->getPasswordHash(), $password)) {
            throw InvalidCredentialsException::create();
        }

        return $user;
    }
}
