<?php

declare(strict_types=1);

namespace App\Tests\Http\Web\Session;

use App\Crypto\DerivedKey;
use App\Crypto\SessionKeyVault;
use App\Crypto\SessionKeyWrapper;
use App\Http\Web\Session\WrappedSessionKeyVault;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class WrappedSessionKeyVaultTest extends TestCase
{
    private function key(): DerivedKey
    {
        return new DerivedKey(random_bytes(DerivedKey::LENGTH));
    }

    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    /**
     * @param array<string, string> $cookies
     */
    private function request(Session $session, array $cookies = [], bool $secure = false): Request
    {
        $request = Request::create($secure ? 'https://localhost/' : 'http://localhost/', cookies: $cookies);
        $request->setSession($session);

        return $request;
    }

    private function vaultFor(Request $request): WrappedSessionKeyVault
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new WrappedSessionKeyVault($stack, new SessionKeyWrapper());
    }

    private function unlockCookie(Request $request): Cookie
    {
        $cookie = $request->attributes->get(WrappedSessionKeyVault::COOKIE_ATTRIBUTE);
        self::assertInstanceOf(Cookie::class, $cookie);

        return $cookie;
    }

    /**
     * Rejoue la requête suivante du navigateur : même stockage serveur, cookies renvoyés tels quels.
     */
    private function nextRequest(Session $session, Request $previous): Request
    {
        $cookie = $this->unlockCookie($previous);

        return $this->request($session, [$cookie->getName() => (string) $cookie->getValue()]);
    }

    public function testImplementsTheVaultContract(): void
    {
        self::assertInstanceOf(SessionKeyVault::class, $this->vaultFor($this->request($this->session())));
    }

    public function testReturnsTheKeyStoredDuringTheSameRequest(): void
    {
        $vault = $this->vaultFor($this->request($this->session()));
        $key = $this->key();

        $vault->put('session-1', $key);

        self::assertSame($key, $vault->get('session-1'));
    }

    public function testServerStorageNeverHoldsTheKeyInTheClear(): void
    {
        $session = $this->session();
        $key = $this->key();

        $this->vaultFor($this->request($session))->put('session-1', $key);

        $stored = serialize($session->all());
        self::assertStringNotContainsString($key->bytes(), $stored);
        self::assertStringNotContainsString(
            sodium_bin2base64($key->bytes(), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            $stored,
        );
    }

    public function testPutQueuesAHardenedUnlockCookie(): void
    {
        $request = $this->request($this->session());

        $this->vaultFor($request)->put('session-1', $this->key());

        $cookie = $this->unlockCookie($request);
        self::assertSame('TERRILDB_UNLOCK', $cookie->getName());
        self::assertNotSame('', (string) $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertSame(0, $cookie->getExpiresTime(), 'cookie de session : disparaît avec le navigateur');
        self::assertFalse($cookie->isSecure());
    }

    public function testUnlockCookieIsSecureOverHttps(): void
    {
        $request = $this->request($this->session(), secure: true);

        $this->vaultFor($request)->put('session-1', $this->key());

        self::assertTrue($this->unlockCookie($request)->isSecure());
    }

    public function testKeyIsRecoveredOnTheNextRequestWithTheCookie(): void
    {
        $session = $this->session();
        $first = $this->request($session);
        $key = $this->key();
        $this->vaultFor($first)->put('session-1', $key);

        $recovered = $this->vaultFor($this->nextRequest($session, $first))->get('session-1');

        self::assertNotNull($recovered);
        self::assertSame($key->bytes(), $recovered->bytes());
    }

    public function testServerStorageAloneCannotUnlockTheKey(): void
    {
        $session = $this->session();
        $this->vaultFor($this->request($session))->put('session-1', $this->key());

        self::assertNull($this->vaultFor($this->request($session))->get('session-1'));
    }

    public function testWrongUnlockCookieDoesNotUnlockTheKey(): void
    {
        $session = $this->session();
        $this->vaultFor($this->request($session))->put('session-1', $this->key());
        $forged = (new SessionKeyWrapper())->generateUnlockSecret();

        $vault = $this->vaultFor($this->request($session, ['TERRILDB_UNLOCK' => $forged]));

        self::assertNull($vault->get('session-1'));
    }

    public function testGarbageUnlockCookieDoesNotUnlockTheKey(): void
    {
        $session = $this->session();
        $this->vaultFor($this->request($session))->put('session-1', $this->key());

        $vault = $this->vaultFor($this->request($session, ['TERRILDB_UNLOCK' => '!!not base64!!']));

        self::assertNull($vault->get('session-1'));
    }

    public function testKeyIsBoundToItsSessionId(): void
    {
        $session = $this->session();
        $first = $this->request($session);
        $this->vaultFor($first)->put('session-1', $this->key());

        self::assertNull($this->vaultFor($this->nextRequest($session, $first))->get('session-2'));
    }

    public function testAnonymousVisitorGetsNoKeyAndNoSessionIsStarted(): void
    {
        $session = $this->session();

        self::assertNull($this->vaultFor($this->request($session))->get('session-1'));
        self::assertFalse($session->isStarted());
    }

    public function testNoCurrentRequestMeansNoKey(): void
    {
        $vault = new WrappedSessionKeyVault(new RequestStack(), new SessionKeyWrapper());

        self::assertNull($vault->get('session-1'));
    }

    public function testForgetWipesTheKeyAndRemovesItFromServerStorage(): void
    {
        $session = $this->session();
        $first = $this->request($session);
        $vault = $this->vaultFor($first);
        $key = $this->key();
        $vault->put('session-1', $key);
        $cookie = $this->unlockCookie($first);

        $vault->forget('session-1');

        self::assertTrue($key->isWiped());
        self::assertNull($vault->get('session-1'));
        $replay = $this->request($session, [$cookie->getName() => (string) $cookie->getValue()]);
        self::assertNull($this->vaultFor($replay)->get('session-1'), 'le cookie rejoué ne rouvre plus rien');
    }

    public function testForgetQueuesTheExpiryOfTheUnlockCookie(): void
    {
        $session = $this->session();
        $request = $this->request($session);
        $vault = $this->vaultFor($request);
        $vault->put('session-1', $this->key());

        $vault->forget('session-1');

        $cookie = $this->unlockCookie($request);
        self::assertSame('TERRILDB_UNLOCK', $cookie->getName());
        self::assertTrue($cookie->isCleared());
    }

    public function testForgetWithoutSessionIsHarmless(): void
    {
        $this->vaultFor($this->request($this->session()))->forget('session-1');

        $this->expectNotToPerformAssertions();
    }

    public function testPutReplacesAndWipesThePreviousKey(): void
    {
        $vault = $this->vaultFor($this->request($this->session()));
        $old = $this->key();
        $new = $this->key();
        $vault->put('session-1', $old);

        $vault->put('session-1', $new);

        self::assertTrue($old->isWiped());
        self::assertSame($new, $vault->get('session-1'));
    }

    public function testResetWipesKeysHeldInMemory(): void
    {
        $vault = $this->vaultFor($this->request($this->session()));
        $key = $this->key();
        $vault->put('session-1', $key);

        $vault->reset();

        self::assertTrue($key->isWiped());
    }

    public function testResetPreventsAKeyLeakingToTheNextRequestOfAWorker(): void
    {
        $session = $this->session();
        $stack = new RequestStack();
        $stack->push($this->request($session));
        $vault = new WrappedSessionKeyVault($stack, new SessionKeyWrapper());
        $vault->put('session-1', $this->key());

        $vault->reset();
        $stack->pop();
        $stack->push($this->request($session));

        self::assertNull($vault->get('session-1'), 'sans cookie, une requête suivante ne récupère rien');
    }
}
