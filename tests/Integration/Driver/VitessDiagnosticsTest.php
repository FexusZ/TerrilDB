<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Driver\TargetConnection;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * Les diagnostics d'un serveur ne se lisent pas à travers vtgate (#40) : il envoie chaque lecture du catalogue à UN tablet, au hasard
 * du routage, et les sessions, verrous, comptes, variables ou la réplication d\'un seul tablet passeraient pour ceux du cluster. Mesuré
 * sur `target-vitess` : toutes les sources répondaient, `replication` disait « aucune » faute de voir le cluster. Sauté si la cible
 * est éteinte (profil « vitess », voir docs/vitess-tests.md).
 */
#[Group('integration')]
final class VitessDiagnosticsTest extends TestCase
{
    private ?TargetConnection $target = null;

    protected function tearDown(): void
    {
        $this->target?->close();
    }

    private function open(): TargetConnection
    {
        try {
            $this->target = (new MySqlDriver(new DbalConnector()))->connect(Engine::MySql, TargetDatabase::parameters('TARGET_VITESS_URL'));
        } catch (SkippedWithMessageException $e) {
            throw $e;
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped('TARGET_VITESS_URL is unreachable (docker compose --profile vitess up -d --wait target-vitess).');
        }

        return $this->target;
    }

    public function testNoThemeIsServedAndTheReasonIsTheTabletScope(): void
    {
        $capabilities = $this->open()->diagnostics()->capabilities();

        self::assertNotSame([], $capabilities);
        foreach ($capabilities as $theme => $status) {
            self::assertSame('unsupported', $status->availability->value, $theme);
            self::assertSame('vitess_tablet_scope', $status->reason, $theme);
        }
    }

    public function testAThemeRouteAnswersUnsupported(): void
    {
        $diagnostics = $this->open()->diagnostics();

        $this->expectException(UnsupportedOperationException::class);
        $diagnostics->sessions();
    }

    public function testPlainMySqlKeepsEveryTheme(): void
    {
        $target = (new MySqlDriver(new DbalConnector()))->connect(Engine::MySql, TargetDatabase::parameters('TARGET_MYSQL_URL'));
        try {
            self::assertSame('available', $target->diagnostics()->capabilities()['sessions']->availability->value);
        } finally {
            $target->close();
        }
    }
}
