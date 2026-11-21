<?php

declare(strict_types=1);

namespace App\Tests\ScriptRun;

use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Connection\SshJump;
use App\Core\Query\WritePermissions;
use App\ScriptRun\Exception\InvalidScriptRunRequestException;
use App\ScriptRun\ScriptRunRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ScriptRunRequestTest extends TestCase
{
    private const SECRET = 'S3cr3t-mot-de-passe-unique';

    private function request(?ConnectionParameters $parameters = null, string $script = 'INSERT INTO t VALUES (1)', int $timeoutMs = 120_000): ScriptRunRequest
    {
        return new ScriptRunRequest(
            Uuid::v7(),
            Engine::PostgreSql,
            $parameters ?? new ConnectionParameters('db.example.test', 5432, 'shop', 'app', self::SECRET),
            $script,
            new WritePermissions(true, false),
            true,
            false,
            $timeoutMs,
            new AuditContext(AuditActor::Web, Uuid::fromString('0192f1c0-0000-7000-8000-000000000001'), null, '203.0.113.5'),
        );
    }

    public function testItSurvivesTheTripThroughJson(): void
    {
        $request = $this->request((new ConnectionParameters('db.example.test', 5432, 'shop', 'app', self::SECRET))->withAddress('203.0.113.7')->withSchema('app'));

        $back = ScriptRunRequest::fromJson($request->toJson());

        self::assertTrue($request->runId->equals($back->runId));
        self::assertSame(Engine::PostgreSql, $back->engine);
        self::assertSame('INSERT INTO t VALUES (1)', $back->script);
        self::assertSame('db.example.test', $back->parameters->host);
        self::assertSame('203.0.113.7', $back->parameters->address());
        self::assertSame('app', $back->parameters->schema);
        self::assertSame('shop', $back->parameters->database);
        self::assertSame(self::SECRET, $back->parameters->password());
        self::assertTrue($back->writes->data);
        self::assertFalse($back->writes->schema);
        self::assertTrue($back->confirmed);
        self::assertFalse($back->dryRun);
        self::assertSame(120_000, $back->timeoutMs);
        self::assertSame(AuditActor::Web, $back->audit->actor);
        self::assertSame('0192f1c0-0000-7000-8000-000000000001', $back->audit->userId?->toRfc4122());
        self::assertNull($back->audit->tokenId);
        self::assertSame('203.0.113.5', $back->audit->ip);
    }

    public function testTheJudgingModeSurvivesTheTripAndDefaultsToStrict(): void
    {
        $request = new ScriptRunRequest(Uuid::v7(), Engine::PostgreSql, new ConnectionParameters('db.example.test', 5432, 'shop', 'app', self::SECRET), 'SELECT 1', WritePermissions::all(), false, false, 120_000, new AuditContext(AuditActor::Web, Uuid::fromString('0192f1c0-0000-7000-8000-000000000001'), null, '203.0.113.5'), true);

        self::assertTrue(ScriptRunRequest::fromJson($request->toJson())->lenient);
        self::assertFalse(ScriptRunRequest::fromJson($this->request()->toJson())->lenient);

        $data = json_decode($request->toJson(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        unset($data['lenient']);
        self::assertFalse(ScriptRunRequest::fromJson(json_encode($data, \JSON_THROW_ON_ERROR))->lenient, 'un message sans le champ reste strict');
    }

    public function testWithoutAPinnedAddressTheHostIsKept(): void
    {
        $back = ScriptRunRequest::fromJson($this->request()->toJson());

        self::assertSame('db.example.test', $back->parameters->address());
    }

    public function testAConnectionThroughAJumpTravelsWithItsTunnelSocket(): void
    {
        $jump = new SshJump('bastion.example.test', 22, 'jump', "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----", null);
        $request = $this->request(new ConnectionParameters('db.internal.example', 5432, 'shop', 'app', self::SECRET, transport: $jump));

        $back = ScriptRunRequest::fromJson($request->toJson());

        self::assertInstanceOf(SshJump::class, $back->parameters->transport);
        self::assertNull($back->parameters->socketPath(), 'sans tunnel ouvert, aucun socket');

        $opened = ScriptRunRequest::fromJson($request->withTunnelSocket('/run/tunnels/abc/tunnel.sock')->toJson());
        self::assertSame('/run/tunnels/abc/tunnel.sock', $opened->parameters->socketPath());
        self::assertSame('db.internal.example', $opened->parameters->host);
    }

    public function testAScriptTooLargeOrEmptyIsRefused(): void
    {
        foreach (['', str_repeat('a', 1_000_001)] as $script) {
            try {
                $this->request(script: $script);
                self::fail('A script of '.\strlen($script).' bytes must be refused.');
            } catch (InvalidScriptRunRequestException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAHoursLongTimeoutIsCarried(): void
    {
        $back = ScriptRunRequest::fromJson($this->request(timeoutMs: 14_400_000)->toJson());

        self::assertSame(14_400_000, $back->timeoutMs);
        $this->expectException(InvalidScriptRunRequestException::class);
        $this->request(timeoutMs: 86_400_001);
    }

    public function testTheTimeoutIsBounded(): void
    {
        $this->expectException(InvalidScriptRunRequestException::class);
        $this->request(timeoutMs: 50);
    }

    public function testABrokenMessageIsRefusedWithoutEchoingItsContent(): void
    {
        foreach (['', 'not json', '{"v":1}', '{"v":9,"run":"x"}', str_repeat('x', ScriptRunRequest::MAX_JSON_BYTES + 1), json_encode(['v' => 1, 'run' => 'not-a-uuid', 'password' => self::SECRET], \JSON_THROW_ON_ERROR)] as $json) {
            try {
                ScriptRunRequest::fromJson($json);
                self::fail('A broken message must be refused.');
            } catch (InvalidScriptRunRequestException $e) {
                self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            }
        }
    }

    public function testTheSecretNeverLeavesThroughADumpOrASerialization(): void
    {
        $request = $this->request();

        self::assertStringNotContainsString(self::SECRET, print_r($request, true));
        self::assertStringNotContainsString(self::SECRET, var_export($request->__debugInfo(), true));
        $this->expectException(\LogicException::class);
        serialize($request);
    }

    public function testAnAuthorThatIsNotValidIsRefused(): void
    {
        $data = json_decode($this->request()->toJson(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        foreach ([null, ['actor' => 'root', 'user' => null, 'token' => null, 'ip' => null], ['actor' => 'web', 'user' => 'not-a-uuid', 'token' => null, 'ip' => null], ['actor' => 'web', 'user' => null, 'token' => null, 'ip' => str_repeat('1', 46)]] as $audit) {
            $data['audit'] = $audit;
            try {
                ScriptRunRequest::fromJson(json_encode($data, \JSON_THROW_ON_ERROR));
                self::fail('An author that is not valid must be refused.');
            } catch (InvalidScriptRunRequestException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
