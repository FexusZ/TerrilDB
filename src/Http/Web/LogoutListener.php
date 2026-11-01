<?php

declare(strict_types=1);

namespace App\Http\Web;

use App\Audit\AuditAction;
use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Audit\AuditLog;
use App\Crypto\SessionKeyVault;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Uid\Uuid;

/**
 * À la déconnexion : la clé de la session est effacée du coffre (et son cookie expiré) avant que la session
 * disparaisse, et la réponse est un 204 plutôt qu'une redirection.
 */
#[AsEventListener(priority: 16)]
final readonly class LogoutListener
{
    public function __construct(
        private SessionKeyVault $vault,
        private AuditLog $audit,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->hasSession()) {
            $this->vault->forget($request->getSession()->getId());
        }
        $identifier = $event->getToken()?->getUser()?->getUserIdentifier();
        if (null !== $identifier && Uuid::isValid($identifier)) {
            $this->audit->record(new AuditContext(AuditActor::Web, Uuid::fromString($identifier), null, $request->getClientIp()), AuditAction::LogoutDone);
        }
        $event->setResponse(new Response(status: Response::HTTP_NO_CONTENT));
    }
}
