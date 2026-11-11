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

    /**
     * Les réponses de Symfony portent déjà ces en-têtes (SecurityHeadersListener) : un `header` qui couvrirait tout le site
     * les doublerait (deux fois X-Frame-Options, que des navigateurs rejettent alors). Il ne vise donc que les fichiers
     * que Caddy sert lui-même (vérifié sur l'image de production : une fois chacun, partout).
     */
    public function testTheCaddyfileHeadersOnlyCoverFilesCaddyServesItself(): void
    {
        $caddyfile = self::read('docker/prod/Caddyfile');

        self::assertMatchesRegularExpression('/^\s*@static\s*\{[^}]*\bfile\b[^}]*\}/m', $caddyfile, 'un matcher @static (fichier existant) est déclaré');
        self::assertDoesNotMatchRegularExpression('/^\s*header\s*\{/m', $caddyfile, 'aucun bloc header sans matcher');
        self::assertDoesNotMatchRegularExpression('/^\s*header\s+\/app\/\*/m', $caddyfile, 'ni sur tout /app/* : la route /app/… sert aussi la SPA par Symfony');
        self::assertMatchesRegularExpression('/^\s*header\s+@static\s*\{/m', $caddyfile);
    }

    public function testTheDevelopmentRouterUsesTheListenerHeaders(): void
    {
        $router = self::read('docker/php/router.php');

        self::assertStringContainsString('SecurityHeadersListener::CONTENT_SECURITY_POLICY', $router);
        self::assertStringContainsString('X-Content-Type-Options', $router);
    }
}
