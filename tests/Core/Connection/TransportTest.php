<?php

declare(strict_types=1);

namespace App\Tests\Core\Connection;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Connection\Socks5Proxy;
use App\Core\Connection\SshJump;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";
    private const FINGERPRINT = 'SHA256:nThbg6kXUpJWGl7E1IGOCspRomTxdCARLviKw6E5SY8';

    private function parameters(SshJump|Socks5Proxy|null $transport): ConnectionParameters
    {
        return new ConnectionParameters('db.internal', 5432, 'shop', 'reader', 'secret', transport: $transport);
    }

    public function testWithoutTransportTheStoredFormatIsUnchanged(): void
    {
        $json = $this->parameters(null)->toJson();

        self::assertStringContainsString('"v":1', $json);
        self::assertStringNotContainsString('transport', $json);
        self::assertNull(ConnectionParameters::fromJson($json)->transport);
    }

    public function testAnSshJumpSurvivesTheEncryptedJson(): void
    {
        $jump = new SshJump('bastion.example', 2222, 'tunnel', self::KEY, self::FINGERPRINT);

        $back = ConnectionParameters::fromJson($this->parameters($jump)->toJson());

        $transport = $back->transport;
        self::assertInstanceOf(SshJump::class, $transport);
        self::assertSame('bastion.example', $transport->host);
        self::assertSame(2222, $transport->port);
        self::assertSame('tunnel', $transport->username);
        self::assertSame(self::KEY, $transport->privateKey());
        self::assertSame(self::FINGERPRINT, $transport->hostKeyFingerprint);
        self::assertSame('db.internal', $back->host, 'la cible finale reste celle de la base');
    }

    public function testASocks5ProxySurvivesTheEncryptedJson(): void
    {
        $back = ConnectionParameters::fromJson($this->parameters(new Socks5Proxy('proxy.example', 1080, 'user', 'pw'))->toJson());

        $transport = $back->transport;
        self::assertInstanceOf(Socks5Proxy::class, $transport);
        self::assertSame('proxy.example', $transport->host);
        self::assertSame('user', $transport->username);
        self::assertSame('pw', $transport->password());
    }

    public function testAProxyWithoutCredentialsIsAllowed(): void
    {
        $back = ConnectionParameters::fromJson($this->parameters(new Socks5Proxy('proxy.example'))->toJson());

        $transport = $back->transport;
        self::assertInstanceOf(Socks5Proxy::class, $transport);
        self::assertSame(1080, $transport->port);
        self::assertNull($transport->username);
        self::assertNull($transport->password());
    }

    public function testNoSecretShowsInADump(): void
    {
        $dump = print_r($this->parameters(new SshJump('bastion.example', 22, 'tunnel', self::KEY)), true)
            .print_r($this->parameters(new Socks5Proxy('proxy.example', 1080, 'user', 'proxy-password')), true);

        self::assertStringNotContainsString('AAAAfaux', $dump);
        self::assertStringNotContainsString('proxy-password', $dump);
        self::assertStringContainsString('bastion.example', $dump);
    }

    public function testTheTransportFollowsTheOtherChanges(): void
    {
        $jump = new SshJump('bastion.example', 22, 'tunnel', self::KEY);
        $parameters = $this->parameters($jump)->withAddress('93.184.216.34')->withDatabase('other')->withSchema('s')->withPassword('new');

        self::assertSame($jump, $parameters->transport);
    }

    public function testTheFirstHopIsTheJumpOrElseTheTarget(): void
    {
        self::assertSame(['db.internal', 5432], $this->parameters(null)->firstHop());
        self::assertSame(['bastion.example', 2222], $this->parameters(new SshJump('bastion.example', 2222, 'u', self::KEY))->firstHop());
        self::assertSame(['proxy.example', 1080], $this->parameters(new Socks5Proxy('proxy.example'))->firstHop());
    }

    /**
     * @return iterable<string, array{callable(): object}>
     */
    public static function invalid(): iterable
    {
        yield 'hôte du bastion vide' => [static fn () => new SshJump('', 22, 'u', self::KEY)];
        yield 'port du bastion hors plage' => [static fn () => new SshJump('b', 70000, 'u', self::KEY)];
        yield 'utilisateur vide' => [static fn () => new SshJump('b', 22, '', self::KEY)];
        yield 'clé qui n\'est pas une clé privée' => [static fn () => new SshJump('b', 22, 'u', 'pas une clé')];
        yield 'clé trop grande' => [static fn () => new SshJump('b', 22, 'u', "-----BEGIN OPENSSH PRIVATE KEY-----\n".str_repeat('A', 20000)."\n-----END OPENSSH PRIVATE KEY-----")];
        yield 'empreinte mal formée' => [static fn () => new SshJump('b', 22, 'u', self::KEY, 'MD5:aa:bb')];
        yield 'hôte du proxy vide' => [static fn () => new Socks5Proxy('')];
        yield 'port du proxy nul' => [static fn () => new Socks5Proxy('p', 0)];
        yield 'identifiant sans mot de passe' => [static fn () => new Socks5Proxy('p', 1080, 'user', null)];
        yield 'mot de passe sans identifiant' => [static fn () => new Socks5Proxy('p', 1080, null, 'pw')];
        yield 'identifiant trop long pour SOCKS5' => [static fn () => new Socks5Proxy('p', 1080, str_repeat('u', 256), 'pw')];
    }

    /**
     * @param callable(): object $build
     */
    #[DataProvider('invalid')]
    public function testRefusesWhatIsNotValid(callable $build): void
    {
        $this->expectException(InvalidConnectionParametersException::class);

        $build();
    }

    public function testRefusesAnUnknownOrIncompleteTransportInStoredJson(): void
    {
        foreach ([
            '{"v":2,"host":"h","port":1,"database":"","username":"u","password":"p","transport":{"type":"carrier-pigeon"}}',
            '{"v":2,"host":"h","port":1,"database":"","username":"u","password":"p","transport":{"type":"ssh","host":"b"}}',
            '{"v":2,"host":"h","port":1,"database":"","username":"u","password":"p","transport":"ssh"}',
            '{"v":3,"host":"h","port":1,"database":"","username":"u","password":"p"}',
        ] as $json) {
            try {
                ConnectionParameters::fromJson($json);
                self::fail('Un format illisible doit être refusé : '.$json);
            } catch (InvalidConnectionParametersException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
