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
}
