<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Connection\SshJump;
use App\Http\Json\ConnectionInput;
use App\Http\Json\JsonInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ConnectionInputTest extends TestCase
{
    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAfaux\n-----END OPENSSH PRIVATE KEY-----\n";
    private const OTHER_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAautre\n-----END OPENSSH PRIVATE KEY-----\n";
    private const FINGERPRINT = 'SHA256:nThbg6kXUpJWGl7E1IGOCspRomTxdCARLviKw6E5SY8';

    /**
     * @param array<string, mixed> $extra
     */
    private function input(array $extra = []): JsonInput
    {
        $body = $extra + ['name' => 'Via', 'engine' => 'postgresql', 'host' => 'db.behind', 'port' => 5432, 'database' => 'shop', 'username' => 'reader', 'password' => 'secret'];

        return JsonInput::fromRequest(Request::create('/x', 'POST', content: json_encode($body, \JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function jump(array $overrides = []): array
    {
        return $overrides + ['type' => 'ssh', 'host' => 'bastion.example', 'port' => 22, 'username' => 'tunnel', 'privateKey' => self::KEY, 'hostKeyFingerprint' => self::FINGERPRINT];
    }

    private function current(?string $fingerprint = self::FINGERPRINT): ConnectionParameters
    {
        return new ConnectionParameters('db.behind', 5432, 'shop', 'reader', 'secret', transport: new SshJump('bastion.example', 22, 'tunnel', self::KEY, $fingerprint));
    }

    private function jumpOf(ConnectionParameters $parameters): SshJump
    {
        self::assertInstanceOf(SshJump::class, $parameters->transport);

        return $parameters->transport;
    }

    public function testWithoutTransportTheConnectionIsDirect(): void
    {
        [, , $parameters] = ConnectionInput::read($this->input(), null);

        self::assertNull($parameters->transport);
    }

    public function testReadsAJump(): void
    {
        [, , $parameters] = ConnectionInput::read($this->input(['transport' => $this->jump()]), null);

        $jump = $this->jumpOf($parameters);
        self::assertSame(['bastion.example', 22, 'tunnel', self::KEY, self::FINGERPRINT], [$jump->host, $jump->port, $jump->username, $jump->privateKey(), $jump->hostKeyFingerprint]);
    }

    public function testThePortDefaultsTo22AndTheFingerprintToNone(): void
    {
        $transport = $this->jump();
        unset($transport['port'], $transport['hostKeyFingerprint']);

        [, , $parameters] = ConnectionInput::read($this->input(['transport' => $transport]), null);

        self::assertSame(22, $this->jumpOf($parameters)->port);
        self::assertNull($this->jumpOf($parameters)->hostKeyFingerprint);
    }

    public function testAnUpdateKeepsTheKeyAndTheFingerprintOfTheSameBastion(): void
    {
        $transport = $this->jump();
        unset($transport['privateKey'], $transport['hostKeyFingerprint']);

        [, , $parameters] = ConnectionInput::read($this->input(['transport' => $transport]), $this->current());

        self::assertSame(self::KEY, $this->jumpOf($parameters)->privateKey());
        self::assertSame(self::FINGERPRINT, $this->jumpOf($parameters)->hostKeyFingerprint);
    }

    public function testAnotherBastionForgetsTheConfirmedFingerprintButAnewKeyDoesNot(): void
    {
        $moved = $this->jump(['host' => 'other.example']);
        unset($moved['hostKeyFingerprint']);
        [, , $parameters] = ConnectionInput::read($this->input(['transport' => $moved]), $this->current());
        self::assertNull($this->jumpOf($parameters)->hostKeyFingerprint);

        $rekeyed = $this->jump(['privateKey' => self::OTHER_KEY]);
        unset($rekeyed['hostKeyFingerprint']);
        [, , $parameters] = ConnectionInput::read($this->input(['transport' => $rekeyed]), $this->current());
        self::assertSame(self::FINGERPRINT, $this->jumpOf($parameters)->hostKeyFingerprint);
        self::assertSame(self::OTHER_KEY, $this->jumpOf($parameters)->privateKey());
    }

    public function testASuppliedFingerprintReplacesTheStoredOne(): void
    {
        $other = 'SHA256:'.str_repeat('B', 43);

        [, , $parameters] = ConnectionInput::read($this->input(['transport' => $this->jump(['hostKeyFingerprint' => $other])]), $this->current());

        self::assertSame($other, $this->jumpOf($parameters)->hostKeyFingerprint);
    }

    public function testAnUpdateWithoutTransportKeepsTheJumpAndAnExplicitNullRemovesIt(): void
    {
        [, , $kept] = ConnectionInput::read($this->input(), $this->current());
        self::assertNotNull($kept->transport);

        [, , $removed] = ConnectionInput::read($this->input(['transport' => null]), $this->current());
        self::assertNull($removed->transport);
    }

    public function testACreationWithoutAKeyIsRefused(): void
    {
        $transport = $this->jump();
        unset($transport['privateKey']);

        $this->expectException(InvalidConnectionParametersException::class);

        ConnectionInput::read($this->input(['transport' => $transport]), null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidJumps(): iterable
    {
        yield 'unknown type' => [['type' => 'socks5']];
        yield 'not a key' => [['privateKey' => 'not a key']];
        yield 'bad fingerprint' => [['hostKeyFingerprint' => 'MD5:aa']];
        yield 'port zero' => [['port' => 0]];
        yield 'empty user' => [['username' => '']];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidJumps')]
    public function testAnInvalidJumpIsRefused(array $override): void
    {
        $this->expectException(InvalidConnectionParametersException::class);

        ConnectionInput::read($this->input(['transport' => $this->jump($override)]), null);
    }

    public function testTheReplicaOptionIsReadAndOffByDefault(): void
    {
        [, , $off] = ConnectionInput::read($this->input(['engine' => 'mysql', 'port' => 3306]), null);
        [, , $on] = ConnectionInput::read($this->input(['engine' => 'mysql', 'port' => 3306, 'readReplica' => true]), null);

        self::assertFalse($off->readReplica);
        self::assertTrue($on->readReplica);
    }

    public function testAnAbsentReplicaFieldKeepsTheStoredChoiceAndFalseRemovesIt(): void
    {
        $current = new ConnectionParameters('db.behind', 3306, 'shop', 'reader', 'secret', readReplica: true);
        $mysql = ['engine' => 'mysql', 'port' => 3306];

        [, , $kept] = ConnectionInput::read($this->input($mysql), $current);
        [, , $removed] = ConnectionInput::read($this->input($mysql + ['readReplica' => false]), $current);

        self::assertTrue($kept->readReplica);
        self::assertFalse($removed->readReplica);
    }

    public function testTheReplicaOptionIsRefusedOutsideMySql(): void
    {
        $this->expectException(InvalidConnectionParametersException::class);

        ConnectionInput::read($this->input(['readReplica' => true]), null);
    }
}
