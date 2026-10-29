<?php

declare(strict_types=1);

namespace App\Http\Web\Session;

use App\Crypto\DerivedKey;
use App\Crypto\Exception\CryptoException;
use App\Crypto\SessionKeyVault;
use App\Crypto\SessionKeyWrapper;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Coffre de la clé de session adossé à la session Symfony : la clé y est déposée enveloppée, le secret
 * de déverrouillage ne vit que dans un cookie dédié. Le stockage serveur seul ne permet donc pas de la
 * retrouver ; il faut aussi le cookie du navigateur.
 *
 * Le cookie est posé (ou expiré) sur la réponse par UnlockCookieListener, via un attribut de requête.
 * L'identifiant de session est lié à l'enveloppe : il doit être celui de la session au moment du put,
 * donc après le renouvellement d'identifiant du login.
 */
#[AsAlias(SessionKeyVault::class)]
final class WrappedSessionKeyVault implements SessionKeyVault, ResetInterface
{
    public const COOKIE_NAME = 'TERRILDB_UNLOCK';
    public const COOKIE_ATTRIBUTE = '_terrildb_unlock_cookie';
    private const STORAGE_KEY = '_terrildb.session_key';

    /** @var array<string, DerivedKey> */
    private array $keys = [];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SessionKeyWrapper $wrapper,
    ) {
    }

    public function put(string $sessionId, DerivedKey $key): void
    {
        $session = $this->requestStack->getSession();
        $request = $this->requestStack->getCurrentRequest() ?? throw new \LogicException('No current request.');

        $previous = $this->keys[$sessionId] ?? null;
        if (null !== $previous && $previous !== $key) {
            $previous->wipe();
        }

        $unlockSecret = $this->wrapper->generateUnlockSecret();
        $session->set(self::STORAGE_KEY, $this->wrapper->wrap($key, $unlockSecret, $sessionId));
        $request->attributes->set(self::COOKIE_ATTRIBUTE, $this->cookie($request, $unlockSecret));

        $this->keys[$sessionId] = $key;
    }

    public function get(string $sessionId): ?DerivedKey
    {
        $held = $this->keys[$sessionId] ?? null;
        if (null !== $held && !$held->isWiped()) {
            return $held;
        }
        unset($this->keys[$sessionId]);

        $request = $this->requestStack->getCurrentRequest();
        $wrapped = $this->readableSession($request)?->get(self::STORAGE_KEY);
        $unlockSecret = $request?->cookies->get(self::COOKIE_NAME);
        if (!\is_string($wrapped) || !\is_string($unlockSecret)) {
            return null;
        }

        try {
            return $this->keys[$sessionId] = $this->wrapper->unwrap($wrapped, $unlockSecret, $sessionId);
        } catch (CryptoException) {
            return null;
        }
    }

    public function forget(string $sessionId): void
    {
        if (isset($this->keys[$sessionId])) {
            $this->keys[$sessionId]->wipe();
            unset($this->keys[$sessionId]);
        }

        $request = $this->requestStack->getCurrentRequest();
        $session = $this->readableSession($request);
        if (null === $request || null === $session) {
            return;
        }

        $session->remove(self::STORAGE_KEY);
        $request->attributes->set(self::COOKIE_ATTRIBUTE, $this->cookie($request, null));
    }

    /**
     * Appelé entre deux requêtes d'un processus long : aucune clé ne doit survivre à sa requête.
     */
    public function reset(): void
    {
        foreach ($this->keys as $key) {
            $key->wipe();
        }
        $this->keys = [];
    }

    /**
     * Ne démarre jamais de session pour un visiteur qui n'en a pas déjà une.
     */
    private function readableSession(?Request $request): ?SessionInterface
    {
        if (null === $request || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        if (!$session->isStarted() && !$request->hasPreviousSession()) {
            return null;
        }

        return $session;
    }

    private function cookie(Request $request, #[\SensitiveParameter] ?string $unlockSecret): Cookie
    {
        return Cookie::create(
            self::COOKIE_NAME,
            $unlockSecret,
            null === $unlockSecret ? 1 : 0,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }
}
