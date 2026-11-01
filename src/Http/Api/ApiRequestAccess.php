<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\ApiToken\ApiTokenAccess;
use App\ApiToken\Exception\InvalidApiTokenException;
use App\Audit\AuditActor;
use App\Audit\AuditContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Le jeton qui a authentifié la requête en cours, avec le compte et la clé qu'il a ouverts. La clé est effacée
 * de la mémoire à la fin de la requête.
 */
#[AsEventListener]
final readonly class ApiRequestAccess
{
    public const ATTRIBUTE = '_terrildb_api_access';

    public function __construct(private RequestStack $requests)
    {
    }

    public function get(): ApiTokenAccess
    {
        $access = $this->requests->getMainRequest()?->attributes->get(self::ATTRIBUTE);

        return $access instanceof ApiTokenAccess ? $access : throw InvalidApiTokenException::create();
    }

    public function auditContext(AuditActor $actor): AuditContext
    {
        $access = $this->get();

        return new AuditContext($actor, $access->user->getId(), $access->token->getId(), $this->requests->getMainRequest()?->getClientIp());
    }

    public function __invoke(TerminateEvent $event): void
    {
        $access = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if ($access instanceof ApiTokenAccess) {
            $access->key->wipe();
        }
    }
}
