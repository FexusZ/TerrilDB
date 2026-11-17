<?php

declare(strict_types=1);

namespace App\Tests\ApiToken;

use App\ApiToken\ApiTokenAccess;
use App\ApiToken\Exception\ApiTokenPermissionException;
use App\ApiToken\TokenPermissions;
use App\Crypto\DerivedKey;
use App\Crypto\KeyDerivation;
use App\Entity\ApiToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ApiTokenAccessTest extends TestCase
{
    private function access(TokenPermissions $permissions): ApiTokenAccess
    {
        $now = new \DateTimeImmutable('2026-11-01T10:00:00+00:00');
        $user = new User('alice@example.test', 'alice', '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA', (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), $now);
        $token = new ApiToken(Uuid::v7(), $user, 'CI', str_repeat('a', 64), 'v1:AAAAwrapped', [], $now, null, permissions: $permissions);

        return new ApiTokenAccess($user, new DerivedKey(str_repeat('k', 32)), $token);
    }

    public function testATokenWithoutTheRightSeesNoDiagnostic(): void
    {
        $this->expectException(ApiTokenPermissionException::class);

        $this->access(new TokenPermissions(writeData: true, writeSchema: true, createConnections: true))->requireDiagnostic();
    }

    public function testATokenWithTheRightPasses(): void
    {
        $this->access(new TokenPermissions(diagnostic: true))->requireDiagnostic();

        $this->addToAssertionCount(1);
    }

    public function testSchemaAndExportRightsAreRefusedByDefaultAndIndependent(): void
    {
        foreach (['requireReadSchema', 'requireExport'] as $check) {
            try {
                $this->access(new TokenPermissions(writeData: true, writeSchema: true, createConnections: true, diagnostic: true, singleStep: true))->$check();
                self::fail($check.' must refuse a token without the right.');
            } catch (ApiTokenPermissionException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->access(new TokenPermissions(readSchema: true))->requireReadSchema();
        $this->access(new TokenPermissions(export: true))->requireExport();
        $this->addToAssertionCount(2);

        $this->expectException(ApiTokenPermissionException::class);
        $this->access(new TokenPermissions(readSchema: true))->requireExport();
    }

    public function testATokenWithoutTheExportRightReadsAtMostFiveHundredRows(): void
    {
        self::assertSame(500, ApiTokenAccess::ROWS_WITHOUT_EXPORT);
        $plain = $this->access(new TokenPermissions(writeData: true, readSchema: true));

        self::assertSame(500, $plain->readRowLimit(100_000));
        self::assertSame(500, $plain->readRowLimit(501));
        self::assertSame(500, $plain->readRowLimit(500));
        self::assertSame(3, $plain->readRowLimit(3));
    }

    public function testATokenWithTheExportRightKeepsTheServerRowCeiling(): void
    {
        $exporter = $this->access(new TokenPermissions(export: true));

        self::assertSame(100_000, $exporter->readRowLimit(100_000));
        self::assertSame(1_000, $exporter->readRowLimit(1_000));
    }

    public function testNewReadRightsDoNotCountAsWriting(): void
    {
        self::assertFalse((new TokenPermissions(readSchema: true, export: true))->canWrite());
    }
}
