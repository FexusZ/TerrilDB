<?php

declare(strict_types=1);

namespace App\Tests\Connection;

use App\Connection\Exception\ForbiddenTargetHostException;
use App\Connection\HostResolver;
use App\Connection\TargetHostPolicy;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Socks5Proxy;
use App\Core\Connection\SshJump;
use App\Crypto\KeyDerivation;
use App\Entity\Role;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TargetHostPolicyTest extends TestCase
{
    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";
    private const HASH = '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA';

    private function user(Role $role): User
    {
        return new User('person@example.test', 'person', self::HASH, (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2026-11-01T10:00:00Z'), $role);
    }

    /**
     * @param array<string, list<string>> $names
     */
    private function policy(array $names = [], ?string $internalHost = 'db'): TargetHostPolicy
    {
        $names += ['db' => ['172.18.0.2']];
        $resolver = new class($names) implements HostResolver {
            /** @param array<string, list<string>> $names */
            public function __construct(private array $names)
            {
            }

            public function resolve(string $host): array
            {
                return filter_var($host, \FILTER_VALIDATE_IP) ? [$host] : ($this->names[$host] ?? []);
            }
        };

        return new TargetHostPolicy($resolver, $internalHost);
    }

    private function parameters(string $host): ConnectionParameters
    {
        return new ConnectionParameters($host, 5432, 'shop', 'reader', 'secret');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbidden(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1'];
        yield 'whole loopback block' => ['127.8.9.10'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'bracketed IPv6 loopback' => ['[::1]'];
        yield 'unspecified' => ['0.0.0.0'];
        yield 'IPv6 unspecified' => ['::'];
        yield 'cloud metadata (link-local)' => ['169.254.169.254'];
        yield 'IPv6 link-local' => ['fe80::1'];
        yield 'IPv4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'localhost' => ['localhost'];
        yield 'any .localhost name' => ['api.localhost'];
        yield 'name resolving to loopback' => ['sneaky.example'];
        yield 'name with one loopback among public answers' => ['mixed.example'];
        yield 'the internal database by name' => ['db'];
        yield 'the internal database by address' => ['172.18.0.2'];
        yield 'another name for the internal database' => ['alias.example'];
    }

    #[DataProvider('forbidden')]
    public function testAMemberCannotReachTheServerItself(string $host): void
    {
        $policy = $this->policy(['sneaky.example' => ['127.0.0.1'], 'mixed.example' => ['93.184.216.34', '127.0.0.1'], 'alias.example' => ['172.18.0.2']]);

        $this->expectException(ForbiddenTargetHostException::class);

        $policy->assertAllowed($this->user(Role::Member), $this->parameters($host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowed(): iterable
    {
        yield 'public address' => ['8.8.8.8'];
        yield 'public name' => ['db.example.org'];
        yield 'private 10.x' => ['10.0.0.5'];
        yield 'private 192.168.x' => ['192.168.1.20'];
        yield 'another docker address' => ['172.18.0.9'];
        yield 'public IPv6' => ['2606:4700:4700::1111'];
        yield 'unresolvable name (the connection will fail by itself)' => ['nowhere.invalid'];
    }

    #[DataProvider('allowed')]
    public function testAMemberMayReachOtherMachines(string $host): void
    {
        $this->policy(['db.example.org' => ['93.184.216.34']])->assertAllowed($this->user(Role::Member), $this->parameters($host));

        $this->addToAssertionCount(1);
    }

    #[DataProvider('forbidden')]
    public function testAnAdministratorMayReachAnything(string $host): void
    {
        $this->policy(['sneaky.example' => ['127.0.0.1'], 'mixed.example' => ['93.184.216.34', '127.0.0.1'], 'alias.example' => ['172.18.0.2']])
            ->assertAllowed($this->user(Role::Admin), $this->parameters($host));

        $this->addToAssertionCount(1);
    }

    public function testWithoutAnInternalDatabaseHostOnlyTheMachineItselfIsForbidden(): void
    {
        $policy = $this->policy(internalHost: null);

        $policy->assertAllowed($this->user(Role::Member), $this->parameters('172.18.0.2'));
        $this->expectException(ForbiddenTargetHostException::class);
        $policy->assertAllowed($this->user(Role::Member), $this->parameters('127.0.0.1'));
    }

    public function testTheRefusalNeverEchoesWhatWasResolved(): void
    {
        try {
            $this->policy(['sneaky.example' => ['127.0.0.1']])->assertAllowed($this->user(Role::Member), $this->parameters('sneaky.example'));
            self::fail('Expected a refusal.');
        } catch (ForbiddenTargetHostException $refused) {
            self::assertStringNotContainsString('127.0.0.1', $refused->getMessage());
        }
    }

    public function testItGivesBackTheAddressToConnectToWithIpv4First(): void
    {
        $resolver = $this->countingResolver(['db.example.org' => ['2606:4700:4700::1111', '93.184.216.34']]);
        $policy = new TargetHostPolicy($resolver, null);

        $address = $policy->pinnedAddress($this->user(Role::Member), $this->parameters('db.example.org'));

        self::assertSame('93.184.216.34', $address);
        self::assertSame(['db.example.org' => 1], $resolver->calls);
    }

    public function testTheNameIsResolvedOnceWhateverTheAnswersLater(): void
    {
        $resolver = $this->countingResolver(['flip.example' => ['93.184.216.34']]);
        $policy = new TargetHostPolicy($resolver, null);

        $first = $policy->pinnedAddress($this->user(Role::Member), $this->parameters('flip.example'));
        $resolver->names['flip.example'] = ['127.0.0.1'];

        self::assertSame('93.184.216.34', $first);
        self::assertSame(1, $resolver->calls['flip.example']);
    }

    public function testAnAdministratorIsPinnedWithoutBeingRefused(): void
    {
        $policy = new TargetHostPolicy($this->countingResolver(['sneaky.example' => ['127.0.0.1']]), null);

        self::assertSame('127.0.0.1', $policy->pinnedAddress($this->user(Role::Admin), $this->parameters('sneaky.example')));
    }

    public function testAMemberStillCannotBePinnedToTheServerItself(): void
    {
        $policy = new TargetHostPolicy($this->countingResolver(['sneaky.example' => ['127.0.0.1']]), null);

        $this->expectException(ForbiddenTargetHostException::class);
        $policy->pinnedAddress($this->user(Role::Member), $this->parameters('sneaky.example'));
    }

    public function testALiteralAddressIsItsOwnPin(): void
    {
        $policy = new TargetHostPolicy($this->countingResolver(), null);

        self::assertSame('10.0.0.5', $policy->pinnedAddress($this->user(Role::Member), $this->parameters('10.0.0.5')));
    }

    public function testAnUnresolvableNameIsNotPinned(): void
    {
        $policy = new TargetHostPolicy($this->countingResolver(), null);

        self::assertNull($policy->pinnedAddress($this->user(Role::Member), $this->parameters('nowhere.invalid')));
    }

    /**
     * @param array<string, list<string>> $names
     */
    private function countingResolver(array $names = []): CountingResolver
    {
        return new CountingResolver($names);
    }

    public function testAMemberCannotUseTheMachineItselfAsJumpOrProxy(): void
    {
        $this->expectException(ForbiddenTargetHostException::class);

        $this->policy()->assertAllowed($this->user(Role::Member), $this->withTransport('db.example', new SshJump('127.0.0.1', 22, 'u', self::KEY)));
    }

    public function testAMemberCannotUseTheInternalDatabaseAsProxy(): void
    {
        $this->expectException(ForbiddenTargetHostException::class);

        $this->policy()->assertAllowed($this->user(Role::Member), $this->withTransport('db.example', new Socks5Proxy('db')));
    }

    public function testTheFinalTargetBehindAJumpIsTheJumpsBusiness(): void
    {
        $this->policy()->assertAllowed($this->user(Role::Member), $this->withTransport('127.0.0.1', new SshJump('93.184.216.34', 22, 'u', self::KEY)));

        $this->addToAssertionCount(1);
    }

    public function testAnAdministratorMayUseALocalJump(): void
    {
        $this->policy()->assertAllowed($this->user(Role::Admin), $this->withTransport('db.example', new SshJump('127.0.0.1', 22, 'u', self::KEY)));

        $this->addToAssertionCount(1);
    }

    private function withTransport(string $host, SshJump|Socks5Proxy $transport): ConnectionParameters
    {
        return new ConnectionParameters($host, 5432, 'shop', 'reader', 'secret', transport: $transport);
    }
}
