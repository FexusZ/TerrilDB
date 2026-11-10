<?php

declare(strict_types=1);

namespace App\Tests\Http\Web;

use App\Http\Web\SecurityHeadersListener;
use PHPUnit\Framework\TestCase;

/**
 * Caddy (production) et le routeur de développement servent /app/ sans passer par Symfony : leurs en-têtes doivent
 * rester ceux du listener.
 */
final class StaticFilesHeadersTest extends TestCase
{
    private static function read(string $relative): string
    {
        $content = file_get_contents(\dirname(__DIR__, 3).'/'.$relative);
        self::assertIsString($content);

        return $content;
    }

    public function testTheCaddyfileSetsTheSameHeadersAsTheListener(): void
    {
        $caddyfile = self::read('docker/prod/Caddyfile');

        self::assertStringContainsString('Content-Security-Policy "'.SecurityHeadersListener::CONTENT_SECURITY_POLICY.'"', $caddyfile);
        self::assertStringContainsString('X-Content-Type-Options "nosniff"', $caddyfile);
        self::assertStringContainsString('X-Frame-Options "DENY"', $caddyfile);
        self::assertStringContainsString('Referrer-Policy "no-referrer"', $caddyfile);
    }

    public function testTheDevelopmentRouterUsesTheListenerHeaders(): void
    {
        $router = self::read('docker/php/router.php');

        self::assertStringContainsString('SecurityHeadersListener::CONTENT_SECURITY_POLICY', $router);
        self::assertStringContainsString('X-Content-Type-Options', $router);
    }
}
