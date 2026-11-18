<?php

declare(strict_types=1);

namespace App\Tests\Core\Connection;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Connection\SshJump;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";
    private const FINGERPRINT = 'SHA256:nThbg6kXUpJWGl7E1IGOCspRomTxdCARLviKw6E5SY8';

    private function parameters(?SshJump $transport): ConnectionParameters
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

    public function testNoSecretShowsInADump(): void
    {
        $dump = print_r($this->parameters(new SshJump('bastion.example', 22, 'tunnel', self::KEY)), true);

        self::assertStringNotContainsString('AAAAfaux', $dump);
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
            // SOCKS5 est reporté (compagnon VPN, #14/#15) : un tel transport n'est pas pris en charge, donc illisible.
            '{"v":2,"host":"h","port":1,"database":"","username":"u","password":"p","transport":{"type":"socks5","host":"p","port":1080,"username":null,"password":null}}',
        ] as $json) {
            try {
                ConnectionParameters::fromJson($json);
                self::fail('Un format illisible doit être refusé : '.$json);
            } catch (InvalidConnectionParametersException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnInvalidStoredJumpDoesNotLeakItsKeyThroughTheTrace(): void
    {
        $json = json_encode([
            'v' => 2, 'host' => 'db.internal', 'port' => 5432, 'database' => 'shop', 'username' => 'reader', 'password' => 'secret',
            'transport' => ['type' => 'ssh', 'host' => 'bastion.example', 'username' => 'tunnel', 'privateKey' => self::KEY],
        ], \JSON_THROW_ON_ERROR);

        try {
            ConnectionParameters::fromJson($json);
            self::fail('invalid transport expected');
        } catch (InvalidConnectionParametersException $error) {
            self::assertStringNotContainsString('AAAAfaux', $error->getTraceAsString());
            self::assertStringNotContainsString('AAAAfaux', json_encode($error->getTrace(), \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
        }
    }
}
