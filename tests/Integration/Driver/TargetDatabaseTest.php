<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/** Une cible de test sans mot de passe est une exception NOMMÉE, jamais un assouplissement général. */
final class TargetDatabaseTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TARGET_MYSQL_URL');
        putenv('TARGET_VITESS_URL');
    }

    public function testATargetUrlWithoutPasswordIsRefused(): void
    {
        putenv('TARGET_MYSQL_URL=pdo-mysql://root@host:3306/shop');

        $this->expectException(AssertionFailedError::class);
        TargetDatabase::parameters('TARGET_MYSQL_URL');
    }

    public function testATargetUrlWithAPasswordIsRead(): void
    {
        putenv('TARGET_MYSQL_URL=pdo-mysql://root:secret@host:3306/shop');

        self::assertSame('secret', TargetDatabase::parameters('TARGET_MYSQL_URL')->password());
    }

    public function testTheStandaloneVitessTargetIsTheOnlyOneAllowedWithoutPassword(): void
    {
        putenv('TARGET_VITESS_URL=pdo-mysql://root@host:33577/shop');

        self::assertSame('', TargetDatabase::parameters('TARGET_VITESS_URL')->password());
    }
}
